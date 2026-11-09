@extends('pdf.layout')

@section('title', $title)

@section('document-meta')
    <h1>{{ $title }}</h1>
@endsection

@section('content')
    <p>{{ $body }}</p>
@endsection

@section('footer')
    Document généré par FDS Business
@endsection
