@extends('pdf.layout')

@section('title', 'Conditions générales de location')

@section('content')
    <h1>Conditions générales de location</h1>

    @if (blank($terms))
        <p><strong>Conditions générales non saisies.</strong> Le super admin les saisit dans Réglages, Contrats ; elles seront validées par le conseil juridique (Q15).</p>
    @else
        @foreach (preg_split("/\n\s*\n/", (string) $terms) as $block)
            @if (str_starts_with(trim($block), '#'))
                <h2>{{ ltrim(trim($block), "# ") }}</h2>
            @else
                <p>@foreach (explode("\n", trim($block)) as $line){{ $line }}@if (! $loop->last)<br>@endif @endforeach</p>
            @endif
        @endforeach
    @endif
@endsection
