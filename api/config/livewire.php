<?php

/*
|------------------------------------------------------------------------------
| Livewire — overrides only
|------------------------------------------------------------------------------
|
| Livewire merges its own config under this key, so anything absent here keeps
| the package default and there is no full published copy to keep in step with
| upgrades. The merge is a shallow array_merge, though: a top-level key written
| here replaces the package's whole array for that key, so `navigate` below
| must list every option it has, not just the one being changed.
|
*/

return [

    'navigate' => [
        // The bar that crosses the top while a soft navigation is in flight —
        // see AdminPanelProvider::panel() for why the panel navigates this way.
        // Kept on: it is the only feedback that a click registered, and without
        // a document reload the browser's own spinner never appears.
        'show_progress_bar' => true,

        // Livewire ships #2299dd. The admin has no blue in it — the accent is
        // ink (--bsd-accent in the admin theme), which is what the active nav
        // pill and the primary buttons are already filled with.
        'progress_bar_color' => '#1a1a1a',
    ],

];
