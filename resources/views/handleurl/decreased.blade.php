@extends('main')
@section('content')
    <div class="flex-center position-ref full-height">
        <div class="content">
            <div class="title m-b-md">
                Исходная ссылка
            </div>
            <div class="links">
                <a href="https://{{ $link->long_url }}">https://{{ $link->long_url }}</a>
            </div>
            <div class="title m-b-md">
                Укороченная ссылка
            </div>
            <div class="links">
                <a href="{{ $link->short_url }}">{{ $link->short_url }}</a>
            </div>
        </div>
    </div>
@stop