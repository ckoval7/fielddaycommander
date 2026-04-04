<div
    wire:key="{{ $widgetId }}"
    wire:poll.visible.15s
    x-data="{
        chartInstance: null,
        chartData: @js($chartData),
        isTv: @js($size === 'tv'),
        canvasId: @js('chart-canvas-' . $widgetId),

        init() {
            this.$nextTick(() => {
                this.renderChart();
            });

            // Watch for Livewire updates to chartData
            this.$watch('chartData', (newData) => {
                this.updateChart(newData);
            });

            // Resize chart when edit mode changes (container padding shifts)
            window.addEventListener('edit-mode-changed', () => {
                this.$nextTick(() => {
                    if (this.chartInstance) {
                        this.chartInstance.resize();
                    }
                });
            });
        },

        destroy() {
            if (this.chartInstance) {
                this.chartInstance.destroy();
                this.chartInstance = null;
            }
        },

        renderChart() {
            const canvas = this.$refs.canvas;
            if (!canvas) {
                return;
            }

            if (this.chartInstance) {
                this.chartInstance.destroy();
                this.chartInstance = null;
            }

            // Use globally available Chart.js
            if (!window.Chart) {
                console.error('Chart.js is not available. Make sure it is loaded in app.js');
                return;
            }

            const ctx = canvas.getContext('2d');
            const config = this.buildConfig();

            this.chartInstance = new window.Chart(ctx, config);
        },

        buildConfig() {
            const data = this.chartData;
            const type = data.chart_type || 'bar';
            const isTv = this.isTv;
            const isPie = type === 'pie' || type === 'doughnut';

            const baseFontSize = isTv ? 16 : 12;
            const titleFontSize = isTv ? 20 : 14;
            const borderWidth = isTv ? 3 : 2;

            const datasets = (data.datasets || []).map(ds => ({
                ...ds,
                borderWidth: borderWidth,
            }));

            return {
                type: type,
                data: {
                    labels: data.labels || [],
                    datasets: datasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 400,
                    },
                    plugins: {
                        legend: {
                            display: isPie,
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: baseFontSize,
                                    weight: isTv ? 'bold' : 'normal',
                                },
                                padding: isTv ? 16 : 10,
                                color: this.getTextColor(),
                            },
                        },
                        tooltip: {
                            titleFont: {
                                size: baseFontSize + 2,
                            },
                            bodyFont: {
                                size: baseFontSize,
                            },
                        },
                    },
                    scales: isPie ? {} : {
                        x: {
                            ticks: {
                                font: {
                                    size: baseFontSize,
                                    weight: isTv ? 'bold' : 'normal',
                                },
                                color: this.getTextColor(),
                                maxRotation: 45,
                            },
                            grid: {
                                color: this.getGridColor(),
                            },
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: {
                                    size: baseFontSize,
                                    weight: isTv ? 'bold' : 'normal',
                                },
                                color: this.getTextColor(),
                                precision: 0,
                            },
                            grid: {
                                color: this.getGridColor(),
                            },
                        },
                    },
                },
            };
        },

        getTextColor() {
            const theme = document.documentElement.getAttribute('data-theme');
            return theme === 'dark' ? 'rgba(255, 255, 255, 0.87)' : 'rgba(0, 0, 0, 0.87)';
        },

        getGridColor() {
            const theme = document.documentElement.getAttribute('data-theme');
            return theme === 'dark' ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
        },

        updateChart(newData) {
            // Note: chartData is already updated by Livewire/Alpine reactivity
            // We don't need to manually set this.chartData = newData here

            if (!this.chartInstance) {
                this.renderChart();
                return;
            }

            const config = this.buildConfig();

            if (this.chartInstance.config.type !== config.type) {
                // Chart type changed, need to re-render
                this.renderChart();
                return;
            }

            // Update chart data and options
            this.chartInstance.data = config.data;
            this.chartInstance.options = config.options;
            this.chartInstance.update('none');
        },
    }"
    class="h-full"
>
    <x-card
        class="h-full flex flex-col {{ $size === 'tv' ? 'shadow-lg' : 'shadow-sm' }}"
    >
        {{-- Header with title and summary value --}}
        <div class="flex items-baseline justify-between mb-2">
            <h3 class="{{ $size === 'tv' ? 'text-xl font-bold' : 'text-sm font-semibold' }} text-base-content">
                {{ $chartData['title'] ?? 'Chart' }}
            </h3>
            @if(!empty($chartData['summary_value']))
                <span class="{{ $size === 'tv' ? 'text-2xl' : 'text-lg' }} font-bold tabular-nums text-primary">
                    {{ $chartData['summary_value'] }}
                </span>
            @endif
        </div>
        {{-- Chart container --}}
        <div
            class="relative flex-1 {{ $size === 'tv' ? 'min-h-[280px]' : 'min-h-[200px]' }}"
            role="img"
            :aria-label="chartData.description || 'Chart visualization'"
        >
            <canvas
                x-ref="canvas"
                :id="canvasId"
                aria-hidden="true"
                tabindex="-1"
            ></canvas>
        </div>

        {{-- Screen reader data table --}}
        <div class="sr-only" role="table" aria-label="{{ $chartData['title'] ?? 'Chart' }} data">
            <div role="rowgroup">
                <div role="row">
                    <span role="columnheader">Category</span>
                    <span role="columnheader">Value</span>
                </div>
            </div>
            <div role="rowgroup">
                @foreach(($chartData['labels'] ?? []) as $index => $label)
                    <div role="row">
                        <span role="cell">{{ $label }}</span>
                        <span role="cell">{{ $chartData['datasets'][0]['data'][$index] ?? 0 }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Empty state --}}
        @if(empty($chartData['labels'] ?? []))
            <div class="absolute inset-0 flex items-center justify-center">
                <p class="text-base-content/50 {{ $size === 'tv' ? 'text-lg' : 'text-sm' }}">
                    No data available
                </p>
            </div>
        @endif

        {{-- Last updated timestamp --}}
        <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($chartData['last_updated_at'] ?? null) }}</div>
    </x-card>
</div>
