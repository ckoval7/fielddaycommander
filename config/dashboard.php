<?php

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
            'icon' => 'o-chart-bar',
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
                        'multipliers' => 'Total Multipliers',
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
            'icon' => 'o-chart-pie',
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
            'icon' => 'o-arrow-trending-up',
            'config_schema' => [
                'metric' => [
                    'type' => 'select',
                    'label' => 'Progress Metric',
                    'options' => [
                        'next_milestone' => 'Next Milestone (50 QSO increments)',
                        'event_goal' => 'Event Goal',
                        'class_target' => 'Operating Class Target',
                        'bonus_progress' => 'Bonus Point Progress',
                    ],
                    'default' => 'next_milestone',
                    'required' => true,
                ],
                'custom_target' => [
                    'type' => 'number',
                    'label' => 'Custom Target (optional)',
                    'placeholder' => 'Leave empty for automatic',
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
            'icon' => 'o-list-bullet',
            'config_schema' => [
                'list_type' => [
                    'type' => 'select',
                    'label' => 'List Type',
                    'options' => [
                        'recent_contacts' => 'Recent Contacts',
                        'active_stations' => 'Active Stations',
                        'equipment_status' => 'Equipment Status',
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
            'description' => 'Display countdown timers or elapsed time',
            'icon' => 'o-clock',
            'config_schema' => [
                'timer_type' => [
                    'type' => 'select',
                    'label' => 'Timer Type',
                    'options' => [
                        'event_countdown' => 'Event Countdown',
                        'time_remaining' => 'Time Remaining',
                        'elapsed_time' => 'Elapsed Time',
                        'next_hour_mark' => 'Next Hour Mark',
                    ],
                    'default' => 'event_countdown',
                    'required' => true,
                ],
                'show_seconds' => [
                    'type' => 'toggle',
                    'label' => 'Show Seconds',
                    'default' => true,
                ],
                'alert_when_near' => [
                    'type' => 'number',
                    'label' => 'Alert Minutes Before End (0 to disable)',
                    'default' => 60,
                    'min' => 0,
                ],
            ],
        ],

        'info_card' => [
            'component' => 'dashboard.widgets.info-card',
            'name' => 'Info Card',
            'description' => 'Display static or semi-static event information',
            'icon' => 'o-information-circle',
            'config_schema' => [
                'info_type' => [
                    'type' => 'select',
                    'label' => 'Information Type',
                    'options' => [
                        'event_details' => 'Event Details',
                        'location' => 'Location & Grid Square',
                        'operating_class' => 'Operating Class',
                        'bonus_points' => 'Bonus Points Summary',
                        'station_info' => 'Station Information',
                    ],
                    'default' => 'event_details',
                    'required' => true,
                ],
                'compact_mode' => [
                    'type' => 'toggle',
                    'label' => 'Compact Mode',
                    'description' => 'Show fewer details',
                    'default' => false,
                ],
            ],
        ],

        'message_traffic_score' => [
            'component' => 'dashboard.widgets.message-traffic-score',
            'name' => 'Message Traffic Score',
            'description' => 'Display SM/SEC message, traffic count, and W1AW bulletin bonus point status',
            'icon' => 'o-envelope',
            'config_schema' => [],
        ],

        'sections_worked' => [
            'component' => 'dashboard.widgets.sections-worked',
            'name' => 'Sections Worked',
            'description' => 'All ARRL sections grouped by call area, showing worked vs not worked',
            'icon' => 'o-map',
            'config_schema' => [],
        ],

        'feed' => [
            'component' => 'dashboard.widgets.feed',
            'name' => 'Activity Feed',
            'description' => 'Live stream of recent activity and events',
            'icon' => 'o-rss',
            'config_schema' => [
                'feed_type' => [
                    'type' => 'select',
                    'label' => 'Feed Type',
                    'options' => [
                        'all_activity' => 'All Activity',
                        'contacts_only' => 'Contacts Only',
                        'milestones_only' => 'Milestones Only',
                        'equipment_events' => 'Equipment Events',
                        'guestbook' => 'Guestbook Entries',
                        'photos' => 'Photo Uploads',
                    ],
                    'default' => 'all_activity',
                    'required' => true,
                ],
                'item_count' => [
                    'type' => 'select',
                    'label' => 'Number of Items',
                    'options' => [
                        '10' => '10 items',
                        '15' => '15 items',
                        '20' => '20 items',
                        '30' => '30 items',
                    ],
                    'default' => '20',
                ],
                'auto_scroll' => [
                    'type' => 'toggle',
                    'label' => 'Auto-scroll to New Items',
                    'default' => true,
                ],
            ],
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
        | Guest Dashboard (Unauthenticated Users)
        |----------------------------------------------------------------------
        |
        | Balanced overview for visitors and unauthenticated users.
        | 8 widgets providing essential event information.
        |
        */

        'guest' => [
            'title' => 'Event Dashboard',
            'description' => 'Default dashboard for guests',
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
                    'id' => 'timer-countdown',
                    'type' => 'timer',
                    'config' => [
                        'timer_type' => 'event_countdown',
                        'show_seconds' => true,
                        'alert_when_near' => 60,
                    ],
                    'row_span' => 2,
                    'order' => 3,
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
                    'order' => 4,
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
                    'order' => 5,
                    'visible' => true,
                ],
                [
                    'id' => 'list-recent-contacts',
                    'type' => 'list_widget',
                    'config' => [
                        'list_type' => 'recent_contacts',
                        'item_count' => '15',
                    ],
                    'col_span' => 2,
                    'row_span' => 6,
                    'order' => 6,
                    'visible' => true,
                ],
                [
                    'id' => 'sections-worked',
                    'type' => 'sections_worked',
                    'config' => [],
                    'row_span' => 6,
                    'order' => 7,
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
                    'order' => 8,
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
                    'order' => 9,
                    'visible' => true,
                ],
            ],
        ],

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
                    'id' => 'timer-countdown',
                    'type' => 'timer',
                    'config' => [
                        'timer_type' => 'event_countdown',
                        'show_seconds' => true,
                        'alert_when_near' => 60,
                    ],
                    'row_span' => 2,
                    'order' => 3,
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
                    'order' => 4,
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
                    'order' => 5,
                    'visible' => true,
                ],
                [
                    'id' => 'list-recent-contacts',
                    'type' => 'list_widget',
                    'config' => [
                        'list_type' => 'recent_contacts',
                        'item_count' => '15',
                    ],
                    'col_span' => 2,
                    'row_span' => 6,
                    'order' => 6,
                    'visible' => true,
                ],
                [
                    'id' => 'sections-worked',
                    'type' => 'sections_worked',
                    'config' => [],
                    'row_span' => 6,
                    'order' => 7,
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
                    'order' => 8,
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
                    'order' => 9,
                    'visible' => true,
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | TV Dashboard (Large Display Mode)
        |----------------------------------------------------------------------
        |
        | Optimized for 16:9 widescreen viewing (1920x1080).
        | 10 widgets in 5-column × 2-row fixed grid layout.
        | Larger fonts, high contrast, bold colors, no scrolling.
        |
        */

        'tv' => [
            'title' => 'TV Dashboard',
            'description' => 'Large format dashboard for display screens',
            'layout_type' => 'tv',
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
                    'id' => 'tv-timer-countdown',
                    'type' => 'timer',
                    'config' => [
                        'timer_type' => 'event_countdown',
                        'show_seconds' => false,
                        'alert_when_near' => 0,
                    ],
                    'order' => 2,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-progress-milestone',
                    'type' => 'progress_bar',
                    'config' => [
                        'metric' => 'next_milestone',
                        'show_percentage' => true,
                    ],
                    'order' => 3,
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
                    'order' => 4,
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
                    'order' => 5,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-stat-sections',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'sections_worked',
                        'show_trend' => false,
                    ],
                    'order' => 6,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-stat-operators',
                    'type' => 'stat_card',
                    'config' => [
                        'metric' => 'operators_count',
                        'show_trend' => false,
                    ],
                    'order' => 7,
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
                    'order' => 8,
                    'visible' => true,
                ],
                [
                    'id' => 'tv-sections-worked',
                    'type' => 'sections_worked',
                    'config' => [],
                    'col_span' => 2,
                    'row_span' => 2,
                    'order' => 9,
                    'visible' => true,
                ],
            ],
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
    | Real-time Update Settings
    |--------------------------------------------------------------------------
    |
    | Configure how widgets handle real-time updates via WebSockets.
    |
    */

    'realtime' => [
        // Batch updates every N seconds for high-frequency events
        'batch_interval' => 3,

        // Fallback polling interval when WebSocket disconnects (seconds)
        'polling_interval' => 10,

        // Show "reconnecting" banner after this many seconds
        'reconnect_banner_delay' => 5,

        // Mark data as stale after this many seconds
        'stale_threshold' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Widget data caching configuration for performance optimization.
    |
    */

    'cache' => [
        // Default cache TTL in seconds
        'ttl' => 3,

        // Cache key prefix
        'prefix' => 'dashboard:widget',

        // TV dashboard cache TTL (can be longer, fewer viewers)
        'tv_ttl' => 5,
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
        // Keyboard shortcut to toggle kiosk mode (case-insensitive)
        'shortcut' => 'f',

        // Show exit hint in kiosk mode
        'show_exit_hint' => true,

        // Auto-enable kiosk mode on TV dashboard
        'auto_enable_on_tv' => true,
    ],

];
