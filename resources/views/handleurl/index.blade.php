@extends('main')
@section('content')
    <div class="flex-center position-ref full-height">
        <form method="post" action="{{ route('decrease_url') }}">
            <label for="longUrl">http(s)://</label>
            <input id="longUrl" type="text" name="long_url" placeholder="google.com">
            <input type="submit" value="Уменьшить">
            {!! csrf_field() !!}
        </form>
    </div>
@stop
@section('js')
    <script>
        $(document).ready(function() {
            $('#longUrl').change(function() {
                var val = $(this).val();
                if (val.indexOf("http://") != -1) {
                    $(this).val(val.substring(7));
                }
                if (val.indexOf("https://") != -1) {
                    $(this).val(val.substring(8));
                }
            });
        });
    </script>
@stop