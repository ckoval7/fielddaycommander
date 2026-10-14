<?php

namespace App\Support;

class NameGenerator
{
    private const FIRST_NAMES = [
        'Alex', 'Andrea', 'Ben', 'Carla', 'Chris', 'Dana', 'Derek', 'Elena', 'Frank', 'Gina',
        'Greg', 'Hannah', 'Ian', 'Julia', 'Kevin', 'Laura', 'Marcus', 'Megan', 'Nathan', 'Olivia',
        'Paul', 'Priya', 'Rachel', 'Ray', 'Sofia', 'Steve', 'Tara', 'Tom', 'Victor', 'Wendy',
    ];

    private const LAST_NAMES = [
        'Allen', 'Bennett', 'Carter', 'Diaz', 'Evans', 'Fischer', 'Foster', 'Gray', 'Hayes', 'Hughes',
        'Kim', 'Lambert', 'Lopez', 'Meyer', 'Nguyen', 'Ortiz', 'Patel', 'Price', 'Reed', 'Russo',
        'Sanders', 'Shaw', 'Sullivan', 'Turner', 'Walsh', 'Ward', 'Weber', 'Young',
    ];

    public static function firstName(): string
    {
        return self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
    }

    public static function lastName(): string
    {
        return self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }
}
