<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Entity/Enum/TimeSlot.php';

use App\Entity\Enum\TimeSlot;

$cases = [
    '2026-07-04 00:00' => TimeSlot::ASTREINTE,
    '2026-07-04 05:59' => TimeSlot::ASTREINTE,
    '2026-07-04 06:00' => TimeSlot::DAY,
    '2026-07-04 19:59' => TimeSlot::DAY,
    '2026-07-04 20:00' => TimeSlot::NIGHT,
    '2026-07-04 23:59' => TimeSlot::NIGHT,
];

foreach ($cases as $date => $expected) {
    $actual = TimeSlot::fromDateTime(new DateTimeImmutable($date));
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "TimeSlot boundary failed for %s: expected %s, got %s\n",
            $date,
            $expected->value,
            $actual->value,
        ));
        exit(1);
    }
}

echo "TimeSlot boundary checks passed.\n";
