<div
    wire:key="{{ $widgetId }}"
    wire:poll.visible.5s
    x-data="{
        chartInstance: null,
        chartData: @js($chartData),
        isTv: @js($size === 'tv'),
        canvasId: @js('chart-canvas-' . $widgetId),

        init() {
            this.$nextTick(() => {
                this.renderChart();
            });
        },

        destroy() {
            if (this.chartInstance) {
                this.chartInstance.destroy();
                this.chartInstance = null;
            }
        },

        async renderChart() {
            const canvas = this.$refs.canvas;
            if (!canvas) {
                return;
            }

            if (this.chartInstance) {
                this.chartInstance.destroy();
                this.chartInstance = null;
            }

            const { Chart, registerables } = await import('chart.js/auto');

            const ctx = canvas.getContext('2d');
            const config = this.buildConfig();

            this.chartInstance = new Chart(ctx, config);
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
            this.chartData = newData;

            if (!this.chartInstance) {
                this.renderChart();
                return;
            }

            const config = this.buildConfig();

            if (this.chartInstance.config.type !== config.type) {
                this.renderChart();
                return;
            }

            this.chartInstance.data = config.data;
            this.chartInstance.options = config.options;
            this.chartInstance.update('none');
        },
    }"
    x-effect="updateChart(chartData)"
    class="h-full"
>
    <x-card
        class="h-full {{ $size === 'tv' ? 'shadow-lg' : 'shadow-sm' }}"
        :title="$chartData['title'] ?? 'Chart'"
    >
        {{-- Chart container --}}
        <div
            class="relative {{ $size === 'tv' ? 'min-h-[280px]' : 'min-h-[200px]' }}"
            role="img"
            :aria-label="chartData.description || 'Chart visualization'"
        >
            <canvas
                x-ref="canvas"
                :id="canvasId"
                aria-hidden="true"
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
    </x-card>
</div>
