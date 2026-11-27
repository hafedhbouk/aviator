<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use RuntimeException;

/**
 * Le réglage de la série a changé entre la préparation du compteur et l'attribution
 * (remise à zéro activée ou retirée à ce moment-là) : aucun numéro n'est attribué.
 */
final class SequenceSeriesChangedException extends RuntimeException {}
