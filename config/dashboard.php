<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | Widget Types Registry
    |--------------------------------------------------------------------------
    |
    | All available widget types and their configuration schemas. Each widget
    | type must have: component, name, description, icon, and config_schema.
    |
    */

    'widget_types' => [

        'stat_card' => [
            'component' => 'dashboard.widgets.stat-card',
            'name' => 'Stat Card',
            'description' => 'Display a single metric as a large number with optional trend indicator',
            'icon' => 'phosphor-chart-bar',
            'config_schema' => [
                'metric' => [
                    'type' => 'select',
                    'label' => 'Metric',
                    'options' => [
                        'total_score' => 'Total Score',
                        'qso_count' => 'QSO Count',
                        'sections_worked' => 'Sections Worked',
                        'operators_count' => 'Active Operators',
                        'stations_count' => 'Active Stations',
                        'points_per_hour' => 'Points Per Hour',
                        'qso_per_hour' => 'QSOs Per Hour',
                        'avg_qso_rate_4h' => 'Avg QSO Rate (4h)',
                        'contacts_last_hour' => 'Contacts Last Hour',
                        'hours_remaining' => 'Hours Remaining',
                        'bonus_points_earned' => 'Bonus Points Earned',
                        'guestbook_count' => 'Guestbook Entries',
                    ],
                    'default' => 'total_score',
                    'required' => true,
                ],
                'show_trend' => [
                    'type' => 'toggle',
                    'label' => 'Show Trend Indicator',
                    'default' => true,
                ],
                'show_comparison' => [
                    'type' => 'toggle',
                    'label' => 'Show Comparison',
                    'default' => true,
                ],
                'comparison_interval' => [
                    'type' => 'select',
                    'label' => 'Comparison Interval',
                    'options' => [
                        '1h' => '1 Hour Ago',
                        '4h' => '4 Hours Ago',
                    ],
                    'default' => '1h',
                ],
            ],
        ],

        'chart' => [
            'component' => 'dashboard.widgets.chart',
            'name' => 'Chart',
            'description' => 'Visualize data with interactive graphs powered by Chart.js',
            'icon' => 'phosphor-chart-pie',
            'config_schema' => [
                'chart_type' => [
                    'type' => 'select',
                    'label' => 'Chart Type',
                    'options' => [
                        'bar' => 'Bar Chart',
                        'line' => 'Line Chart',
                        'pie' => 'Pie Chart',
                        'doughnut' => 'Doughnut Chart',
                    ],
                    'default' => 'line',
                    'required' => true,
                ],
                'data_source' => [
                    'type' => 'select',
                    'label' => 'Data Source',
                    'options' => [
                        'qsos_per_hour' => 'QSOs per Hour',
                        'qsos_per_band' => 'QSOs per Band',
                        'qsos_per_mode' => 'QSOs per Mode',
                    ],
                    'default' => 'qsos_per_hour',
                    'required' => true,
                ],
                'time_range' => [
                    'type' => 'select',
                    'label' => 'Time Range',
                    'options' => [
                        'last_hour' => 'Last Hour',
                        'last_4_hours' => 'Last 4 Hours',
                        'last_12_hours' => 'Last 12 Hours',
                        'event' => 'Entire Event',
                    ],
                    'default' => 'last_12_hours',
                ],
            ],
        ],

        'progress_bar' => [
            'component' => 'dashboard.widgets.progress-bar',
            'name' => 'Progress Bar',
            'description' => 'Track progress toward goals and milestones',
            'icon' => 'phosphor-trend-up',
            'config_schema' => [
                'metric' => [
                    'type' => 'select',
                    'label' => 'Progress Metric',
                    'options' => [
                        'next_milestone' => 'Next Milestone (50 QSO increments)',
                        'event_goal' => 'Event Score Goal',
                        'class_target' => 'Operating Class QSO Target',
                        'bonus_progress' => 'Bonus Point Progress',
                    ],
                    'default' => 'next_milestone',
                    'required' => true,
                ],
                'show_percentage' => [
                    'type' => 'toggle',
                    'label' => 'Show Percentage',
                    'default' => true,
                ],
            ],
        ],

        'list_widget' => [
            'component' => 'dashboard.widgets.list-widget',
            'name' => 'List',
            'description' => 'Display scrollable lists of data',
            'icon' => 'phosphor-list-bullets',
            'config_schema' => [
                'list_type' => [
                    'type' => 'select',
                    'label' => 'List Type',
                    'options' => [
                        'recent_contacts' => 'Recent Contacts',
                        'active_stations' => 'Active Stations',
                        'equipment_status' => 'Equipment Status',
                        'net_traffic' => 'Net Traffic',
                        'station_status' => 'Station Status',
                    ],
                    'default' => 'recent_contacts',
                    'required' => true,
                ],
                'item_count' => [
                    'type' => 'select',
                    'label' => 'Number of Items',
                    'options' => [
                        '5' => '5 items',
                        '10' => '10 items',
                        '15' => '15 items',
                        '20' => '20 items',
                    ],
                    'default' => '15',
                ],
            ],
        ],

        'timer' => [
            'component' => 'dashboard.widgets.timer',
            'name' => 'Timer',
            'description' => 'Countdown to event start or end, with a setup-window heads-up',
            'icon' => 'phosphor-clock',
            'config_schema' => [],
        ],

        'info_card' => [
            'component' => 'dashboard.widgets.info-card',
            'name' => 'Info Card',
            'description' => 'Display static or semi-static event information',
            'icon' => 'phosphor-info',
            'config_schema' => [
                'info_type' => [
                    'type' => 'select',
                    'label' => 'Information Type',
                    'options' => [
                        'event_details' => 'Event Details',
                        'location' => 'Location & Grid Square',
                        'operating_class' => 'Operating Class & Power',
                    ],
                    'default' => 'event_details',
                    'required' => true,
                ],
            ],
        ],

        'feed' => [
            'component' => 'dashboard.widgets.feed',
            'name' => 'Activity Feed',
            'description' => 'Live stream of your notifications: QSO milestones, new sections and equipment events',
            'icon' => 'phosphor-bell',
            'config_schema' => [
                'feed_type' => [
                    'type' => 'select',
                    'label' => 'Show',
                    'options' => [
                        'all_activity' => 'All Activity',
                        'contacts_only' => 'Contacts (milestones and new sections)',
                        'milestones_only' => 'QSO Milestones Only',
                        'equipment_events' => 'Equipment Events',
                    ],
                    'default' => 'all_activity',
                    'required' => true,
                ],
            ],
        ],

        'sections_worked' => [
            'component' => 'dashboard.widgets.sections-worked',
            'name' => 'Sections Worked',
            'description' => 'All ARRL sections grouped by call area, showing worked vs not worked',
            'icon' => 'phosphor-map-trifold',
            'config_schema' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Dashboard Configurations
    |--------------------------------------------------------------------------
    |
    | Predefined dashboard layouts for different user types and modes.
    | These are used when no custom dashboard exists.
    |
    */

    'default_dashboards' => [

        /*
        |----------------------------------------------------------------------
        | User Dashboard (New Authenticated Users)
        |----------------------------------------------------------------------
        |
        | Default dashboard for newly authenticated users.
        | Same as guest dashboard but saved to database as "My Dashboard".
        |
        */

        'user' => [
            'title' => 'My Dashboard',
            'description' => 'Default dashboard for new users',
            'layout_type' => 'grid',
            'widgets' => [
                [
                    'id' => 'stat-total-score',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'total_score',
                        'show_trend' => true,
                        'show_comparison' => true,
                        'comparison_interval' => '1h',
                    ],
                    'row_span' => 2,
                    'order' => 0,
                    'visible' => true,
                ],
                [
                    'id' => 'stat-qso-rate',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'qso_per_hour',
                        'show_trend' => true,
                        'show_comparison' => true,
                        'comparison_interval' => '1h',
                    ],
                    'row_span' => 2,
                    'order' => 1,
                    'visible' => true,
                ],
                [
                    'id' => 'stat-contacts-last-hour',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'contacts_last_hour',
                        'show_trend' => true,
                        'show_comparison' => true,
                        'comparison_interval' => '1h',
                    ],
                    'row_span' => 2,
                    'order' => 2,
                    'visible' => true,
                ],
                [
                    'id' => 'sections-worked',
                    'type' => 'sections_worked',
                    'config' => [],
                    'row_span' => 6,
                    'order' => 3,
                    'visible' => true,
                ],
                [
                    'id' => 'progress-milestone',
                    'type' => 'progress_bar',
                    'config' => [
                        'metric' => 'next_milestone',
                        'show_percentage' => true,
                    ],
                    'row_span' => 2,
                    'order' => 4,
                    'visible' => true,
                ],
                [
                    'id' => 'timer-countdown',
                    'type' => 'timer',
                    'config' => [],
                    'row_span' => 2,
                    'order' => 5,
                    'visible' => true,
                ],
                [
                    'id' => 'chart-qsos-band',
                    'type' => 'chart',
                    'config' => [
                        'chart_type' => 'bar',
                        'data_source' => 'qsos_per_band',
                        'time_range' => 'event',
                    ],
                    'col_span' => 2,
                    'row_span' => 3,
                    'order' => 6,
                    'visible' => true,
                ],
                [
                    'id' => 'chart-qsos-hour',
                    'type' => 'chart',
                    'config' => [
                        'chart_type' => 'line',
                        'data_source' => 'qsos_per_hour',
                        'time_range' => 'last_12_hours',
                    ],
                    'col_span' => 2,
                    'row_span' => 3,
                    'order' => 7,
                    'visible' => true,
                ],
                [
                    'id' => 'stat-bonus-points',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'bonus_points_earned',
                        'show_trend' => false,
                    ],
                    'row_span' => 2,
                    'order' => 8,
                    'visible' => true,
                ],
                [
                    // Shown only at unscored events (see DashboardService::isHiddenForEvent),
                    // where the net log takes the place of the contact log.
                    'id' => 'net-traffic',
                    'type' => 'list_widget',
                    'config' => [
                        'list_type' => 'net_traffic',
                        'item_count' => '10',
                    ],
                    'col_span' => 2,
                    'row_span' => 6,
                    'order' => 9,
                    'visible' => true,
                    'unscored_only' => true,
                ],
                [
                    'id' => 'station-status',
                    'type' => 'list_widget',
                    'config' => [
                        'list_type' => 'station_status',
                        'item_count' => '20',
                    ],
                    'col_span' => 2,
                    'row_span' => 6,
                    'order' => 10,
                    'visible' => true,
                    'unscored_only' => true,
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | TV Dashboard (Large Display Mode)
        |----------------------------------------------------------------------
        |
        | Optimized for 16:9 widescreen viewing (1920x1080), no scrolling.
        | Widgets flow in order through a fixed grid of `columns` columns;
        | `col_span` / `row_span` (default 1) size each tile and the row count
        | is derived from the total cells used. Default layout (6 × 3):
        |
        |   Score | QSOs | Sections | Operators | Milestone | Timer
        |   QSOs/hour     | Recent contacts     | Sections worked
        |   QSOs/band     | (rows 2-3)          | (rows 2-3)
        |
        */

        'tv' => [
            'title' => 'TV Dashboard',
            'description' => 'Large format dashboard for display screens',
            'layout_type' => 'tv',
            'columns' => 6,
            'widgets' => [
                [
                    'id' => 'tv-stat-total-score',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'total_score',
                        'show_trend' => false,
                    ],
                    'order' => 0,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-stat-qso-count',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'qso_count',
                        'show_trend' => false,
                    ],
                    'order' => 1,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-stat-sections',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'sections_worked',
                        'show_trend' => false,
                    ],
                    'order' => 2,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-stat-operators',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'operators_count',
                        'show_trend' => false,
                    ],
                    'order' => 3,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-progress-milestone',
                    'type' => 'progress_bar',
                    'config' => [
                        'metric' => 'next_milestone',
                        'show_percentage' => true,
                    ],
                    'order' => 4,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-timer-countdown',
                    'type' => 'timer',
                    'config' => [],
                    'order' => 5,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-chart-qsos-hour',
                    'type' => 'chart',
                    'config' => [
                        'chart_type' => 'bar',
                        'data_source' => 'qsos_per_hour',
                        'time_range' => 'event',
                    ],
                    'col_span' => 2,
                    'order' => 6,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-list-recent-contacts',
                    'type' => 'list_widget',
                    'config' => [
                        'list_type' => 'recent_contacts',
                        'item_count' => '10',
                    ],
                    'col_span' => 2,
                    'row_span' => 2,
                    'order' => 7,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-sections-worked',
                    'type' => 'sections_worked',
                    'config' => [],
                    'col_span' => 2,
                    'row_span' => 2,
                    'order' => 8,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-chart-qsos-band',
                    'type' => 'chart',
                    'config' => [
                        'chart_type' => 'bar',
                        'data_source' => 'qsos_per_band',
                        'time_range' => 'event',
                    ],
                    'col_span' => 2,
                    'order' => 9,
                    'visible' => true,
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scored-Event Widgets
    |--------------------------------------------------------------------------
    |
    | Widgets that only mean something for a scored contest with a contact
    | log. They are hidden (not removed) while the event in context is
    | unscored, so a saved dashboard shows them again at the next Field Day.
    |
    */

    'scored_only' => [
        'widget_types' => ['sections_worked', 'chart'],
        // Settings values that need scoring or contact logging, keyed by the
        // widget setting that holds them. Unscored events log traffic in the
        // net log, not the contact log, so QSO-based widgets have no data.
        'settings' => [
            'metric' => [
                'total_score', 'sections_worked', 'points_per_hour', 'bonus_points_earned',
                'qso_count', 'qso_per_hour', 'avg_qso_rate_4h', 'contacts_last_hour',
                'operators_count', 'stations_count',
                'next_milestone', 'event_goal', 'class_target', 'bonus_progress',
            ],
            'info_type' => ['operating_class'],
            'list_type' => ['recent_contacts', 'active_stations'],
            'feed_type' => ['contacts_only', 'milestones_only'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Limits
    |--------------------------------------------------------------------------
    |
    | Maximum number of dashboards per user and widgets per dashboard.
    | These limits prevent abuse and maintain performance.
    |
    */

    'limits' => [
        'max_dashboards_per_user' => 10,
        'max_widgets_per_dashboard' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Kiosk Mode
    |--------------------------------------------------------------------------
    |
    | Settings for fullscreen/kiosk mode (TV dashboard).
    |
    */

    'kiosk' => [
        // Keyboard shortcut to toggle browser fullscreen (case-insensitive)
        'shortcut' => 'f',

        // Show the kiosk hint pill (bottom-left) in kiosk mode
        'show_exit_hint' => true,
    ],

];
