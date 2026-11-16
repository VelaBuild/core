{{-- A diff between the site's copy of a file and the newer one, as code:
     side by side (or unified), red for the site's lines, green for the new ones.
     Expects: $title, $diff (unified text), $left / $right (what each side is),
     $back (url) and $backLabel; optional $meta (a line under the title). --}}
@extends('vela::layouts.admin')

@php
    $view = request('view') === 'unified' ? 'unified' : 'split';
    $hunks = \VelaBuild\Core\Support\TextDiff::parse($diff);
    $added = $removed = 0;
    foreach ($hunks as $hunk) {
        foreach ($hunk['lines'] as $line) {
            $added += $line['type'] === 'add';
            $removed += $line['type'] === 'del';
        }
    }
@endphp

@section('styles')
<style>
    .vela-diff { font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); font-size: 12.5px; line-height: 1.55; }
    .vela-diff table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .vela-diff td { padding: 0 8px; vertical-align: top; white-space: pre-wrap; word-break: break-all; }
    .vela-diff .ln { width: 3.5em; text-align: right; color: var(--fg-subtle); user-select: none; border-right: 1px solid var(--border-subtle); }
    .vela-diff .mk { width: 1.4em; text-align: center; user-select: none; color: var(--fg-subtle); }
    .vela-diff .del { background: var(--danger-bg); }
    .vela-diff .del .mk { color: var(--danger); }
    .vela-diff .add { background: var(--success-bg); }
    .vela-diff .add .mk { color: var(--success); }
    .vela-diff .empty { background: var(--bg-muted); }
    .vela-diff .split-gap { border-left: 1px solid var(--border); }
    .vela-diff .hunk td { background: var(--info-bg); color: var(--fg-muted); padding: 4px 8px; }
    .vela-diff .side-head th { padding: 6px 8px; font-family: var(--font-sans); font-size: 12px; font-weight: 600; color: var(--fg-muted); background: var(--bg-subtle); border-bottom: 1px solid var(--border); }
    .vela-diff-legend span { display: inline-block; width: 12px; height: 12px; border-radius: 3px; vertical-align: -1px; margin-right: 4px; }
</style>
@endsection

@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <div class="mr-3">
            <h5 class="mb-1"><i class="fas fa-exchange-alt mr-2"></i><code>{{ $title }}</code></h5>
            @if(!empty($meta))<div class="small text-muted">{{ $meta }}</div>@endif
        </div>
        <div class="d-flex flex-wrap align-items-center">
            <div class="btn-group btn-group-sm mr-2 my-1" role="group" aria-label="View">
                <a href="{{ request()->fullUrlWithQuery(['view' => 'split']) }}" class="btn {{ $view === 'split' ? 'btn-primary' : 'btn-outline-primary' }}">Side by side</a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'unified']) }}" class="btn {{ $view === 'unified' ? 'btn-primary' : 'btn-outline-primary' }}">Unified</a>
            </div>
            <a href="{{ request()->fullUrlWithQuery(['format' => 'text', 'view' => null]) }}" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-primary mr-2 my-1" title="Plain text, to copy or give to an AI"><i class="fas fa-file-alt mr-1"></i> Raw</a>
            <a href="{{ $back }}" class="btn btn-sm btn-outline-primary my-1"><i class="fas fa-arrow-left mr-1"></i> {{ $backLabel }}</a>
        </div>
    </div>

    <div class="card-body">
        @if($hunks === [])
            <p class="mb-0 text-muted">The two files are the same.</p>
        @else
            <p class="small mb-3 vela-diff-legend">
                <span style="background: var(--danger-bg); border: 1px solid var(--danger-border)"></span>{{ $left }}
                <strong class="text-danger ml-1">−{{ $removed }}</strong>
                <span class="ml-3" style="background: var(--success-bg); border: 1px solid var(--success-border)"></span>{{ $right }}
                <strong class="text-success ml-1">+{{ $added }}</strong>
            </p>

            <div class="vela-diff border rounded overflow-auto">
                @if($view === 'split')
                    <table>
                        <colgroup><col style="width:3.5em"><col style="width:1.4em"><col><col style="width:3.5em"><col style="width:1.4em"><col></colgroup>
                        <thead class="side-head">
                            <tr><th colspan="3">{{ $left }}</th><th colspan="3" class="split-gap">{{ $right }}</th></tr>
                        </thead>
                        <tbody>
                        @foreach($hunks as $hunk)
                            <tr class="hunk"><td colspan="6">⋯ {{ $hunk['header'] !== '' ? $hunk['header'] : 'line ' . ($hunk['lines'][0]['old'] ?? $hunk['lines'][0]['new'] ?? 1) }}</td></tr>
                            @foreach(\VelaBuild\Core\Support\TextDiff::sideBySide($hunk['lines']) as $row)
                                <tr>
                                    @php $l = $row['left']; $r = $row['right']; @endphp
                                    @if($l)
                                        @php $cls = $l['type'] === 'del' ? 'del' : ''; @endphp
                                        <td class="ln {{ $cls }}">{{ $l['old'] }}</td><td class="mk {{ $cls }}">{{ $l['type'] === 'del' ? '−' : '' }}</td><td class="{{ $cls }}">{{ $l['text'] }}</td>
                                    @else
                                        <td class="ln empty"></td><td class="mk empty"></td><td class="empty"></td>
                                    @endif
                                    @if($r)
                                        @php $cls = $r['type'] === 'add' ? 'add' : ''; @endphp
                                        <td class="ln split-gap {{ $cls }}">{{ $r['new'] }}</td><td class="mk {{ $cls }}">{{ $r['type'] === 'add' ? '+' : '' }}</td><td class="{{ $cls }}">{{ $r['text'] }}</td>
                                    @else
                                        <td class="ln split-gap empty"></td><td class="mk empty"></td><td class="empty"></td>
                                    @endif
                                </tr>
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <table>
                        <colgroup><col style="width:3.5em"><col style="width:3.5em"><col style="width:1.4em"><col></colgroup>
                        <tbody>
                        @foreach($hunks as $hunk)
                            <tr class="hunk"><td colspan="4">⋯ {{ $hunk['header'] !== '' ? $hunk['header'] : 'line ' . ($hunk['lines'][0]['old'] ?? $hunk['lines'][0]['new'] ?? 1) }}</td></tr>
                            @foreach($hunk['lines'] as $line)
                                @continue($line['type'] === 'note')
                                @php $cls = in_array($line['type'], ['add', 'del'], true) ? $line['type'] : ''; @endphp
                                <tr>
                                    <td class="ln {{ $cls }}">{{ $line['old'] }}</td>
                                    <td class="ln {{ $cls }}">{{ $line['new'] }}</td>
                                    <td class="mk {{ $cls }}">{{ ['add' => '+', 'del' => '−'][$line['type']] ?? '' }}</td>
                                    <td class="{{ $cls }}">{{ $line['text'] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
