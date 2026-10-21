<?php

namespace KimaiPlugin\TimerNotesBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Timestamped notes added to the description of the running timer, from the toolbar.
 */
class TimerNotesBundle extends Bundle implements PluginInterface
{
}
