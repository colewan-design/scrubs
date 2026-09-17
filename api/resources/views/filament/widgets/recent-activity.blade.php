{{--
    Recent activity timeline.

    Rendered as a plain list rather than a table: every row is one sentence and
    a timestamp, and a table's column rules would draw more furniture than the
    content needs.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Recent activity</x-slot>

        @php($activity = $this->getActivity())

        @if ($activity->isEmpty())
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                <x-filament::icon
                    icon="heroicon-o-clock"
                    class="h-8 w-8 text-gray-400 dark:text-gray-500"
                />
                <p class="text-sm font-medium text-gray-950 dark:text-white">Nothing yet</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Orders, stock changes and new accounts will show up here.
                </p>
            </div>
        @else
            <ul role="list" class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($activity as $item)
                    <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                        <span @class([
                            'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                            'bg-gray-50 dark:bg-white/5',
                        ])>
                            <x-filament::icon
                                :icon="$item['icon']"
                                @class(['h-4 w-4', $item['colour']])
                            />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">
                                {{ $item['title'] }}
                            </p>

                            @if (filled($item['subtitle']))
                                {{-- truncate, not wrap: a long product name should
                                     not push the timestamp onto its own line --}}
                                <p class="truncate text-sm text-gray-500 dark:text-gray-400">
                                    {{ $item['subtitle'] }}
                                </p>
                            @endif
                        </div>

                        {{-- Absolute time on hover, because "2 hours ago" is the
                             friendlier default but useless when reconciling. --}}
                        <time
                            class="shrink-0 whitespace-nowrap text-xs text-gray-400 dark:text-gray-500"
                            datetime="{{ $item['at']?->toIso8601String() }}"
                            title="{{ $item['at']?->toDayDateTimeString() }}"
                        >
                            {{ $item['at']?->diffForHumans(short: true) }}
                        </time>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
