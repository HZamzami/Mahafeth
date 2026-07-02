---
name: fluxui-development
description: "Use this skill for Flux UI development in Livewire applications only. Trigger when working with <flux:*> components, building or customizing Livewire component UIs, creating forms, modals, tables, or other interactive elements. Covers: flux: components (buttons, inputs, modals, forms, tables, charts, date-pickers, editors, kanban, tabs, badges, tooltips, etc.), component composition, Tailwind CSS styling, Heroicons/Lucide icon integration, validation patterns, responsive design, and theming. Do not use for non-Livewire frameworks or non-component styling."
license: MIT
metadata:
  author: laravel
---

# Flux UI Development

## Documentation

Use `search-docs` for detailed Flux UI patterns and documentation.

## Basic Usage

This project has a **Flux UI Pro license** (`livewire/flux-pro` is installed via Composer). All free AND Pro components are available.

Flux UI is a component library for Livewire built with Tailwind CSS. It provides components that are easy to use and customize.

Use Flux UI components when available. Fall back to standard Blade components when no Flux component exists for your needs.

<!-- Basic Button -->
```blade
<flux:button variant="primary">Click me</flux:button>
```

## Available Components

**Free:** avatar, badge, brand, breadcrumbs, button, callout, card, checkbox, dropdown, field, heading, icon, input, modal, navbar, otp-input, pagination, profile, progress, radio, select (native), separator, skeleton, switch, table, text, textarea, toast, tooltip

**Pro (installed):** accordion, autocomplete, calendar, carousel, chart, color-picker, command, composer, context, date-picker, editor, file-upload, kanban, pillbox, popover, select variants (listbox/combobox), slider, tabs, timeline, time-picker

**Source of truth for props:** the component stubs in `vendor/livewire/flux-pro/stubs/resources/views/flux/<component>/` — each file's `@props([...])` block lists every supported prop. Check there before guessing an API.

## Icons

Flux includes [Heroicons](https://heroicons.com/) as its default icon set. Search for exact icon names on the Heroicons site - do not guess or invent icon names.

<!-- Icon Button -->
```blade
<flux:button icon="arrow-down-tray">Export</flux:button>
```

For icons not available in Heroicons, use [Lucide](https://lucide.dev/). Import the icons you need with the Artisan command:

```bash
php artisan flux:icon crown grip-vertical github
```

## Common Patterns

### Form Fields

<!-- Form Field -->
```blade
<flux:field>
    <flux:label>Email</flux:label>
    <flux:input type="email" wire:model="email" />
    <flux:error name="email" />
</flux:field>
```

### Modals

<!-- Modal -->
```blade
<flux:modal wire:model="showModal">
    <flux:heading>Title</flux:heading>
    <p>Content</p>
</flux:modal>
```

## Pro Component Patterns & Pitfalls

### Chart (`flux:chart`)

Charts render as a `ui-chart` custom element driven by JavaScript; the inner `flux:chart.svg` is `position: absolute; inset: 0` and the markup lives in an inert `<template>` until the JS hydrates it.

```blade
<flux:chart class="relative aspect-3/1" :value="[
    ['date' => 'Sep 01', 'return' => 1.2],
    ['date' => 'Sep 15', 'return' => 5.9],
]">
    <flux:chart.svg>
        <flux:chart.line field="return" curve="smooth" class="text-blue-500 dark:text-blue-400" />
        <flux:chart.area field="return" curve="smooth" class="text-blue-500/10" />
        <flux:chart.axis axis="x" field="date"><flux:chart.axis.tick /></flux:chart.axis>
        <flux:chart.axis axis="y"><flux:chart.axis.grid /><flux:chart.axis.tick /></flux:chart.axis>
    </flux:chart.svg>
</flux:chart>
```

**Pitfalls (learned the hard way):**
- ALWAYS give the chart an explicit size (`aspect-3/1`, `h-40`, etc.) AND an explicit `relative` class. Flux applies `relative` only via a zero-specificity rule; if it loses, the absolute SVG anchors to the page and paints across the entire viewport. Wrapping the card in `relative overflow-hidden` is a cheap containment guard.
- `field` names must match the data array keys exactly; `flux:chart.axis axis="x"` needs a `field` for labels.
- Colors come from `currentColor` — style lines/areas with `text-*` classes, with `dark:` variants.
- Static data uses `:value="[...]"`, dynamic uses `wire:model="data"` on a Livewire array property.
- Chart types: `chart.line`, `chart.area`, `chart.bar`, `chart.point`. There is NO donut/pie/radial gauge — hand-roll those with SVG `stroke-dasharray` circles.
- Sparklines: plain numeric array + `<flux:chart.svg gutter="0">` with only a line, no axes.
- Subcomponents also available: `chart.tooltip`, `chart.cursor`, `chart.legend`, `chart.summary`, `chart.zero-line`.

### Date/Time (`flux:date-picker`, `flux:calendar`, `flux:time-picker`)

```blade
<flux:date-picker wire:model="date" placeholder="Pick a date" clearable />
<flux:date-picker mode="range" with-presets wire:model="range" />
<flux:calendar mode="multiple" wire:model="dates" />
<flux:time-picker wire:model="time" clearable />
```

- Single date binds a `Y-m-d` string. `mode="range"` binds a `['start' => ..., 'end' => ...]` shape — don't assume a plain string.
- `flux:calendar` is the same engine inline (no input/dropdown); `date-picker` wraps it in a trigger. Useful props: `months="2"`, `with-today`, `with-inputs`, `unavailable`, `week-numbers`, `with-confirmation`, `presets`.
- The trigger is a button by default (`type="button"`); `type="input"`/`with-inputs` gives typeable inputs.

### Enhanced Select (`flux:select variant="listbox|combobox"`)

```blade
<flux:select variant="listbox" searchable clearable multiple placeholder="Choose...">
    <flux:select.option value="a">Option A</flux:select.option>
</flux:select>
```

- The free `flux:select` is a styled native `<select>`. `variant="listbox"` (custom styled, `searchable`, `multiple`, `clearable`) and `variant="combobox"` (type-to-filter) are Pro.
- Options are `flux:select.option`; custom empty state via `flux:select.empty`. `selected-suffix` customizes the "N selected" text for multi-selects.
- `multiple` binds an array — the Livewire property must be an array, not a string.

### Autocomplete (`flux:autocomplete`)

```blade
<flux:autocomplete wire:model="query" placeholder="Search...">
    <flux:autocomplete.item>Apple</flux:autocomplete.item>
</flux:autocomplete>
```

- It's an input with suggestions — the bound value is the raw text, unlike select which binds option values. Set `:filter="false"` when filtering server-side (e.g. items generated from a Livewire search).

### Tabs (`flux:tab.group`)

```blade
<flux:tab.group>
    <flux:tabs wire:model="tab">
        <flux:tab name="profile" icon="user">Profile</flux:tab>
        <flux:tab name="billing">Billing</flux:tab>
    </flux:tabs>
    <flux:tab.panel name="profile">...</flux:tab.panel>
    <flux:tab.panel name="billing">...</flux:tab.panel>
</flux:tab.group>
```

- Everything must be inside `flux:tab.group`; each `flux:tab` `name` must match a `flux:tab.panel` `name`. Variants: `segmented`, `pills`; `scrollable` for overflow.

### Accordion (`flux:accordion`)

```blade
<flux:accordion transition>
    <flux:accordion.item expanded>
        <flux:accordion.heading>Question?</flux:accordion.heading>
        <flux:accordion.content>Answer.</flux:accordion.content>
    </flux:accordion.item>
</flux:accordion>
```

- By default multiple items can be open; add `exclusive` to allow only one.

### Rich Text Editor (`flux:editor`)

```blade
<flux:editor wire:model="content" label="Description" toolbar="heading | bold italic underline | bullet ordered | link" />
```

- Binds an **HTML string** — always sanitize/escape appropriately on output, and use `->nullable()`/`text` columns sized for HTML.
- Toolbar is a space/pipe-separated string of item names (see `vendor/livewire/flux-pro/stubs/resources/views/flux/editor/` for the full item list). It ships its own JS chunk (`editor.js`) that Flux loads automatically — no manual asset wiring.
- Works inside `flux:field` with `flux:label`/`flux:error` like any input.

### Popover / Context / Command

- `flux:popover` = free-form floating panel; pair inside `flux:dropdown` with a trigger. Unlike `flux:menu`, content is arbitrary markup.
- `flux:context` wraps an element and shows a `flux:menu` on **right-click**: `<flux:context><div>target</div><flux:menu>...</flux:menu></flux:context>`. `position` prop defaults to `bottom end`.
- `flux:command` is a command palette: `flux:command.input` + `flux:command.items` > `flux:command.item` (with `icon`, `kbd` props). Usually placed inside a modal for a ⌘K experience.

### Kanban (`flux:kanban`)

```blade
<flux:kanban>
    <flux:kanban.column heading="To do">
        <flux:kanban.cards>
            <flux:kanban.card heading="Task title">...</flux:kanban.card>
        </flux:kanban.cards>
    </flux:kanban.column>
</flux:kanban>
```

- Columns support `header`/`footer` slots; cards accept arbitrary content. Wire up drag/drop persistence yourself via Livewire events.

### Other Pro components (quick reference)

- **`flux:slider`** — `wire:model`, `min`/`max`/`step`; `range` prop makes it two-handle (binds an array).
- **`flux:pillbox`** — multi-value tag input; binds an array. Options via `flux:pillbox.option`.
- **`flux:color-picker`** — `format="hex"` default; `swatches`, `dropper`, `copyable`, `clearable` props.
- **`flux:carousel`** — slides as children; `arrows` (default true), `indicators`, `autoplay`, `fade`.
- **`flux:timeline`** — `flux:timeline.item` children; `horizontal` and `align` props.
- **`flux:composer`** — chat-style input (textarea + action slots `actions-leading`/`actions-trailing`); binds via `wire:model`.
- **`flux:file-upload`** — takes its name from `wire:model`; requires Livewire's `WithFileUploads` trait on the component.

## Pro Licensing & Assets (operational pitfalls)

- Local credentials live in the project's `auth.json` (gitignored). Every environment running `composer install` needs its own auth: GitHub Actions uses the `FLUX_USERNAME`/`FLUX_LICENSE_KEY` repo secrets consumed by a `composer config http-basic.composer.fluxui.dev ...` workflow step; Laravel Cloud needs the credentials in its dashboard. A 401 on `composer.fluxui.dev` means missing/empty credentials in that environment, not a code problem.
- After installing or updating flux-pro while `npm run dev` is running, Tailwind may serve stale CSS and the browser stale JS — restart the dev server and hard-refresh before debugging "broken" components.
- `resources/css/app.css` must `@source` the flux-pro stubs (this project does) so Tailwind generates the classes Pro components apply to themselves.

## Verification

1. Check component renders correctly
2. Test interactive states
3. Verify mobile responsiveness
4. For JS-driven components (chart, editor, command), verify in the browser — feature tests only prove the Blade compiles, not that the element hydrates

## Common Pitfalls

- Not checking if a Flux component exists before creating custom implementations
- Guessing component props instead of reading the stub's `@props` block or using `search-docs`
- Forgetting `dark:` variants — this app supports both themes even when mockups are dark-only
- Not following existing project patterns for Flux usage
- Overriding Flux views in `resources/views/flux/` without checking the vendor original first
