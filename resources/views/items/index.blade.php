@extends('layouts.app')
@section('title', 'Inventory')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Inventory</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">Products and repair tasks available on the sale screen.</p>
    </div>
    <a href="{{ route('items.create') }}" class="btn-primary">+ New item</a>
</div>

<form method="GET" action="{{ route('items.index') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4"
      x-data="{
          debounce: null,
          liveSearch() {
              clearTimeout(this.debounce);
              this.debounce = setTimeout(() => this.fetchResults(), 400);
          },
          fetchResults() {
              const url = new URL(this.$root.action);
              url.search = new URLSearchParams(new FormData(this.$root)).toString();

              fetch(url, { headers: { 'X-Inventory-Search': '1' } })
                  .then(response => response.text())
                  .then(html => {
                      document.getElementById('items-results').innerHTML = html;
                      history.replaceState(null, '', url);
                  });
          },
      }">
    <div class="min-w-56 flex-1">
        <label class="label" for="q">Search</label>
        <input id="q" name="q" type="search" class="field" placeholder="Item name&hellip;" value="{{ $search }}"
               x-on:input="liveSearch()">
    </div>
    <div>
        <label class="label" for="type">Type</label>
        <select id="type" name="type" class="field" x-on:change="fetchResults()">
            <option value="">All types</option>
            @foreach ($types as $type)
                <option value="{{ $type->value }}" @selected($activeType === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="status">Status</label>
        <select id="status" name="status" class="field" x-on:change="fetchResults()">
            <option value="">All statuses</option>
            <option value="active" @selected($activeStatus === 'active')>Active only</option>
            <option value="inactive" @selected($activeStatus === 'inactive')>Retired only</option>
        </select>
    </div>
    <div>
        <label class="label" for="category">Category</label>
        <select id="category" name="category" class="field" x-on:change="fetchResults()">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) $activeCategory === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-dark">Filter</button>
    @if ($search || $activeType || $activeStatus || $activeCategory)
        <a href="{{ route('items.index') }}" class="btn-ghost">Clear</a>
    @endif
</form>

<div id="items-results">@include('items._results')</div>
@endsection
