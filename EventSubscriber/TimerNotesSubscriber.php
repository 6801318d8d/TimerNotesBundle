<?php

namespace KimaiPlugin\TimerNotesBundle\EventSubscriber;

use App\Event\ThemeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds two buttons next to the running timer in the toolbar:
 * - "note" opens a small box with the description of the running timesheet and a new line that starts with the
 *   current time ("14:32 "): what is typed there is appended to the description, and the timer keeps running. Enter
 *   saves, Shift+Enter starts another line, Esc closes. The time alone, with nothing typed after it, is not saved;
 * - "edit" opens Kimai's full edit dialog of the running timesheet (saved with an empty end, it keeps running).
 *
 * Kimai only lets a running timesheet be edited from its row in "My times"; the toolbar has the stop button only.
 * The box saves through the API (PATCH of the description), which reuses the session of the page, then triggers
 * Kimai's "kimai.timesheetUpdate" so that lists reload; the dialog is opened with Kimai's own modal plugin.
 */
final class TimerNotesSubscriber implements EventSubscriberInterface
{
    private const STYLE = <<<'HTML'
        <style>
        .ticktac-menu .tn-button { margin-left: .25rem; }
        .tn-panel { position: fixed; z-index: 1030; width: min(32rem, calc(100vw - 16px)); }
        .tn-panel textarea { min-height: 9rem; resize: vertical; }
        </style>
        HTML;

    private const SCRIPT = <<<'HTML'
        <script>
        document.addEventListener('kimai.initialized', function (event) {
            const config = __TIMER_NOTES_CONFIG__;
            const labels = config.labels;
            // the toolbar has no timer without the permission to track time; the page has one per screen size
            const menus = document.querySelectorAll('.ticktac-menu');
            if (menus.length === 0) {
                return;
            }
            let api, alerts, events, modal;
            try {
                api = event.detail.kimai.getPlugin('api');
                alerts = event.detail.kimai.getPlugin('alert');
                events = event.detail.kimai.getPlugin('event');
                modal = event.detail.kimai.getPlugin('modal');
            } catch (error) {
                console.warn('TimerNotesBundle disabled:', error);
                return;
            }
            const pad = (value) => String(value).padStart(2, '0');
            const clock = (date) => pad(date.getHours()) + ':' + pad(date.getMinutes());

            // Kimai's own icons for "comment" and "edit" (config/packages/tabler.yaml)
            function addButton(menu, className, icon, label) {
                const button = document.createElement('a');
                button.href = '#';
                button.className = 'btn btn-default btn-icon px-sm-2 tn-button ' + className;
                button.title = label;
                button.setAttribute('aria-label', label);
                button.innerHTML = '<i class="' + icon + '"></i>';
                menu.appendChild(button);

                return button;
            }

            // inside the menu of the stop button, so Kimai shows and hides them together with the running timer
            const buttons = [];
            const editButtons = [];
            for (const menu of menus) {
                const button = addButton(menu, 'tn-note', 'far fa-comment', labels.add);
                button.setAttribute('aria-expanded', 'false');
                buttons.push(button);
                editButtons.push(addButton(menu, 'tn-edit', 'far fa-edit', labels.edit));
            }
            let anchor = buttons[0];

            // the running entry, or nothing (then Kimai is told to hide the timer, which was stopped elsewhere)
            function withActiveEntry(callback) {
                api.get(config.active, {}, function (entries) {
                    if (entries.length === 0) {
                        events.trigger('kimai.timesheetUpdate');
                        return;
                    }
                    callback(entries[0]);
                });
            }

            const panel = document.createElement('div');
            panel.className = 'card shadow tn-panel';
            panel.hidden = true;
            panel.setAttribute('role', 'dialog');
            panel.innerHTML = `
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <div class="tn-title small text-secondary me-auto"></div>
                        <button type="button" class="btn-close tn-close"></button>
                    </div>
                    <textarea class="form-control" rows="6"></textarea>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <span class="tn-hint small text-secondary"></span>
                        <div class="d-flex gap-2 ms-auto">
                            <button type="button" class="btn tn-close tn-cancel"></button>
                            <button type="button" class="btn btn-primary tn-save"></button>
                        </div>
                    </div>
                </div>`;
            const title = panel.querySelector('.tn-title');
            const textarea = panel.querySelector('textarea');
            const saveButton = panel.querySelector('.tn-save');
            panel.querySelector('.btn-close').setAttribute('aria-label', labels.close);
            panel.querySelector('.tn-hint').textContent = labels.hint;
            panel.querySelector('.tn-cancel').textContent = labels.cancel;
            saveButton.textContent = labels.save;
            textarea.setAttribute('aria-label', labels.add);
            document.body.appendChild(panel);

            let entry = null;
            let initial = '';
            let stamp = '';
            let saving = false;

            // under the clicked button, aligned to its right edge, inside the window
            function place() {
                const rect = anchor.getBoundingClientRect();
                const width = panel.offsetWidth;
                panel.style.top = Math.round(rect.bottom + 6) + 'px';
                panel.style.left = Math.round(Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8))) + 'px';
            }

            function open() {
                withActiveEntry(function (active) {
                    entry = active;
                    const project = entry.project || {};
                    let text = [(project.customer || {}).name, project.name].filter(Boolean).join(' › ');
                    if (entry.activity && entry.activity.name) {
                        text += ' · ' + entry.activity.name;
                    }
                    // the API gives "2026-10-21T14:00:00+0200", which Date.parse() only reads with "+02:00"
                    const begin = Date.parse(String(entry.begin).replace(/([+-]\d{2})(\d{2})$/, '$1:$2'));
                    if (!isNaN(begin)) {
                        text += ' — ' + labels.since.replace('%time%', clock(new Date(begin)));
                    }
                    title.textContent = text;

                    initial = (entry.description || '').replace(/\s+$/, '');
                    stamp = clock(new Date());
                    textarea.value = (initial === '' ? '' : initial + '\n') + stamp + ' ';
                    panel.hidden = false;
                    anchor.setAttribute('aria-expanded', 'true');
                    place();
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                    textarea.scrollTop = textarea.scrollHeight;
                });
            }

            function close() {
                panel.hidden = true;
                anchor.setAttribute('aria-expanded', 'false');
                entry = null;
            }

            // the description to save: without the time of the new line when nothing was typed after it
            function getText() {
                const lines = textarea.value.replace(/\s+$/, '').split('\n');
                if (lines[lines.length - 1].trim() === stamp) {
                    lines.pop();
                }

                return lines.join('\n').replace(/\s+$/, '');
            }

            function isChanged() {
                return entry !== null && getText() !== initial;
            }

            function save() {
                if (entry === null || saving) {
                    return;
                }
                const text = getText();
                if (text === initial) {
                    close();
                    return;
                }
                saving = true;
                saveButton.disabled = true;
                api.patch(config.patch.replace('000', entry.id), {description: text}, function () {
                    saving = false;
                    saveButton.disabled = false;
                    close();
                    alerts.success('action.update.success');
                    events.trigger('kimai.timesheetUpdate');
                }, function (error) {
                    // keep the box open, so that the note is not lost
                    saving = false;
                    saveButton.disabled = false;
                    api.handleError('action.update.error', error);
                });
            }

            for (const button of buttons) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (panel.hidden) {
                        anchor = button;
                        open();
                    } else if (isChanged()) {
                        textarea.focus();
                    } else {
                        close();
                    }
                });
            }
            textarea.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                    e.preventDefault();
                    save();
                }
            });
            panel.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    close();
                }
            });
            // Kimai's full edit dialog of the running entry; saved with an empty end, the timer keeps running
            for (const button of editButtons) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (isChanged()) {
                        textarea.focus();
                        return;
                    }
                    close();
                    withActiveEntry(function (active) {
                        modal.openUrlInModal(config.edit.replace('000', active.id));
                    });
                });
            }
            saveButton.addEventListener('click', save);
            for (const element of panel.querySelectorAll('.tn-close')) {
                element.addEventListener('click', close);
            }
            // a click elsewhere closes the box, unless a note has been typed
            document.addEventListener('mousedown', function (e) {
                if (!panel.hidden && !panel.contains(e.target) && !anchor.contains(e.target) && !isChanged()) {
                    close();
                }
            });
            window.addEventListener('resize', function () {
                if (!panel.hidden) {
                    place();
                }
            });
        });
        </script>
        HTML;

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly TranslatorInterface $translator,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::STYLESHEET => ['onStylesheet', 100],
            ThemeEvent::JAVASCRIPT => ['onJavascript', 100],
        ];
    }

    private function isEnabled(ThemeEvent $event): bool
    {
        // the login page triggers the events too, without user
        return $event->getUser() !== null && $this->auth->isGranted('edit_own_timesheet');
    }

    public function onStylesheet(ThemeEvent $event): void
    {
        if ($this->isEnabled($event)) {
            $event->addContent(self::STYLE);
        }
    }

    public function onJavascript(ThemeEvent $event): void
    {
        if (!$this->isEnabled($event)) {
            return;
        }
        $config = [
            'active' => $this->router->generate('active_timesheet'),
            // like Kimai's own stop URL in the toolbar: "000" is replaced by the id of the running timesheet
            'patch' => $this->router->generate('patch_timesheet', ['id' => '000']),
            'edit' => $this->router->generate('timesheet_edit', ['id' => '000']),
            'labels' => [
                'add' => $this->translator->trans('timer_notes.add'),
                'edit' => $this->translator->trans('timer_notes.edit'),
                'since' => $this->translator->trans('timer_notes.since'),
                'hint' => $this->translator->trans('timer_notes.hint'),
                'save' => $this->translator->trans('action.save'),
                'cancel' => $this->translator->trans('cancel'),
                'close' => $this->translator->trans('action.close'),
            ],
        ];
        $json = json_encode($config, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $event->addContent(str_replace('__TIMER_NOTES_CONFIG__', $json, self::SCRIPT));
    }
}
