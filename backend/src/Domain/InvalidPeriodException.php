<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Некоректний період звіту (дата, порядок, довжина). Окремий клас, щоб контролер перетворював на 400 лише його:
 * загальний InvalidArgumentException — це баг у коді, і він має лишатися 500, а не маскуватися під помилку клієнта.
 */
final class InvalidPeriodException extends InvalidArgumentException
{
}
