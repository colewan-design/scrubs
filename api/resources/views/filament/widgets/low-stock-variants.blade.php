{{--
    Table widget with a status tab strip above it.

    Filament's own tab support lives on ListRecords pages, so the strip is
    rendered here and drives a Livewire property the table query reads. The
    table itself is still Filament's — only the strip is ours.
--}}
<x-filament-widgets::widget>
    <div class="fi-wi-table">
        @php($counts = $this->getTabCounts())

        <x-filament::tabs class="mb-3">
            @foreach ([
                'all' => ['label' => 'All', 'count' => $counts['all']],
                'out' => ['label' => 'Out of stock', 'count' => $counts['out']],
                'low' => ['label' => 'Low stock', 'count' => $counts['low']],
            ] as $key => $tab)
                <x-filament::tabs.item
                    :active="$this->statusTab === $key"
                    :badge="$tab['count']"
                    wire:click="setStatusTab('{{ $key }}')"
                    wire:key="stock-tab-{{ $key }}"
                >
                    {{ $tab['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        {{ $this->table }}
    </div>
</x-filament-widgets::widget>
