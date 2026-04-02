<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sections = [
            // W1 - New England (7 sections)
            ['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'country' => 'US'],
            ['code' => 'EMA', 'name' => 'Eastern Massachusetts', 'region' => 'W1', 'country' => 'US'],
            ['code' => 'ME', 'name' => 'Maine', 'region' => 'W1', 'country' => 'US'],
            ['code' => 'NH', 'name' => 'New Hampshire', 'region' => 'W1', 'country' => 'US'],
            ['code' => 'RI', 'name' => 'Rhode Island', 'region' => 'W1', 'country' => 'US'],
            ['code' => 'VT', 'name' => 'Vermont', 'region' => 'W1', 'country' => 'US'],
            ['code' => 'WMA', 'name' => 'Western Massachusetts', 'region' => 'W1', 'country' => 'US'],

            // W2 - Mid-Atlantic (6 sections)
            ['code' => 'ENY', 'name' => 'Eastern New York', 'region' => 'W2', 'country' => 'US'],
            ['code' => 'NLI', 'name' => 'New York City-Long Island', 'region' => 'W2', 'country' => 'US'],
            ['code' => 'NNJ', 'name' => 'Northern New Jersey', 'region' => 'W2', 'country' => 'US'],
            ['code' => 'NNY', 'name' => 'Northern New York', 'region' => 'W2', 'country' => 'US'],
            ['code' => 'SNJ', 'name' => 'Southern New Jersey', 'region' => 'W2', 'country' => 'US'],
            ['code' => 'WNY', 'name' => 'Western New York', 'region' => 'W2', 'country' => 'US'],

            // W3 - East Coast (4 sections)
            ['code' => 'DE', 'name' => 'Delaware', 'region' => 'W3', 'country' => 'US'],
            ['code' => 'EPA', 'name' => 'Eastern Pennsylvania', 'region' => 'W3', 'country' => 'US'],
            ['code' => 'MDC', 'name' => 'Maryland-DC', 'region' => 'W3', 'country' => 'US'],
            ['code' => 'WPA', 'name' => 'Western Pennsylvania', 'region' => 'W3', 'country' => 'US'],

            // W4 - Southeast (10 sections)
            ['code' => 'AL', 'name' => 'Alabama', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'GA', 'name' => 'Georgia', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'KY', 'name' => 'Kentucky', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'NC', 'name' => 'North Carolina', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'NFL', 'name' => 'Northern Florida', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'SC', 'name' => 'South Carolina', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'SFL', 'name' => 'Southern Florida', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'WCF', 'name' => 'West Central Florida', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'TN', 'name' => 'Tennessee', 'region' => 'W4', 'country' => 'US'],
            ['code' => 'VA', 'name' => 'Virginia', 'region' => 'W4', 'country' => 'US'],

            // KP4 - Puerto Rico & Virgin Islands (2 sections)
            ['code' => 'PR', 'name' => 'Puerto Rico', 'region' => 'KP4', 'country' => 'US'],
            ['code' => 'VI', 'name' => 'Virgin Islands', 'region' => 'KP4', 'country' => 'US'],

            // W5 - South Central (8 sections)
            ['code' => 'AR', 'name' => 'Arkansas', 'region' => 'W5', 'country' => 'US'],
            ['code' => 'LA', 'name' => 'Louisiana', 'region' => 'W5', 'country' => 'US'],
            ['code' => 'MS', 'name' => 'Mississippi', 'region' => 'W5', 'country' => 'US'],
            ['code' => 'NM', 'name' => 'New Mexico', 'region' => 'W5', 'country' => 'US'],
            ['code' => 'NTX', 'name' => 'North Texas', 'region' => 'W5', 'country' => 'US'],
            ['code' => 'OK', 'name' => 'Oklahoma', 'region' => 'W5', 'country' => 'US'],
            ['code' => 'STX', 'name' => 'South Texas', 'region' => 'W5', 'country' => 'US'],
            ['code' => 'WTX', 'name' => 'West Texas', 'region' => 'W5', 'country' => 'US'],

            // W6 - California (9 sections)
            ['code' => 'EB', 'name' => 'East Bay', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'LAX', 'name' => 'Los Angeles', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'ORG', 'name' => 'Orange', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'SB', 'name' => 'Santa Barbara', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'SCV', 'name' => 'Santa Clara Valley', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'SDG', 'name' => 'San Diego', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'SF', 'name' => 'San Francisco', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'SJV', 'name' => 'San Joaquin Valley', 'region' => 'W6', 'country' => 'US'],
            ['code' => 'SV', 'name' => 'Sacramento Valley', 'region' => 'W6', 'country' => 'US'],

            // KH6 - Hawaii/Pacific (1 section)
            ['code' => 'PAC', 'name' => 'Pacific', 'region' => 'KH6', 'country' => 'US'],

            // W7 - Mountain (9 sections)
            ['code' => 'AZ', 'name' => 'Arizona', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'EWA', 'name' => 'Eastern Washington', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'ID', 'name' => 'Idaho', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'MT', 'name' => 'Montana', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'NV', 'name' => 'Nevada', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'OR', 'name' => 'Oregon', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'UT', 'name' => 'Utah', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'WWA', 'name' => 'Western Washington', 'region' => 'W7', 'country' => 'US'],
            ['code' => 'WY', 'name' => 'Wyoming', 'region' => 'W7', 'country' => 'US'],

            // W8 - Great Lakes (3 sections)
            ['code' => 'MI', 'name' => 'Michigan', 'region' => 'W8', 'country' => 'US'],
            ['code' => 'OH', 'name' => 'Ohio', 'region' => 'W8', 'country' => 'US'],
            ['code' => 'WV', 'name' => 'West Virginia', 'region' => 'W8', 'country' => 'US'],

            // W9 - Midwest (3 sections)
            ['code' => 'IL', 'name' => 'Illinois', 'region' => 'W9', 'country' => 'US'],
            ['code' => 'IN', 'name' => 'Indiana', 'region' => 'W9', 'country' => 'US'],
            ['code' => 'WI', 'name' => 'Wisconsin', 'region' => 'W9', 'country' => 'US'],

            // W0 - Central (8 sections)
            ['code' => 'CO', 'name' => 'Colorado', 'region' => 'W0', 'country' => 'US'],
            ['code' => 'IA', 'name' => 'Iowa', 'region' => 'W0', 'country' => 'US'],
            ['code' => 'KS', 'name' => 'Kansas', 'region' => 'W0', 'country' => 'US'],
            ['code' => 'MN', 'name' => 'Minnesota', 'region' => 'W0', 'country' => 'US'],
            ['code' => 'MO', 'name' => 'Missouri', 'region' => 'W0', 'country' => 'US'],
            ['code' => 'NE', 'name' => 'Nebraska', 'region' => 'W0', 'country' => 'US'],
            ['code' => 'ND', 'name' => 'North Dakota', 'region' => 'W0', 'country' => 'US'],
            ['code' => 'SD', 'name' => 'South Dakota', 'region' => 'W0', 'country' => 'US'],

            // KL7 - Alaska (1 section)
            ['code' => 'AK', 'name' => 'Alaska', 'region' => 'KL7', 'country' => 'US'],

            // VE - Canada RAC (14 sections) - Updated 2025
            ['code' => 'AB', 'name' => 'Alberta', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'BC', 'name' => 'British Columbia', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'GH', 'name' => 'Golden Horseshoe', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'MB', 'name' => 'Manitoba', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'NB', 'name' => 'New Brunswick', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'NL', 'name' => 'Newfoundland/Labrador', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'NS', 'name' => 'Nova Scotia', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'ONE', 'name' => 'Ontario East', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'ONN', 'name' => 'Ontario North', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'ONS', 'name' => 'Ontario South', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'PE', 'name' => 'Prince Edward Island', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'QC', 'name' => 'Quebec', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'SK', 'name' => 'Saskatchewan', 'region' => 'VE', 'country' => 'CA'],
            ['code' => 'TER', 'name' => 'Territories', 'region' => 'VE', 'country' => 'CA'],

            // DX - International (1 section)
            ['code' => 'DX', 'name' => 'DX (International)', 'region' => 'DX', 'country' => 'DX'],
        ];

        foreach ($sections as $section) {
            \App\Models\Section::create($section);
        }
    }
}
