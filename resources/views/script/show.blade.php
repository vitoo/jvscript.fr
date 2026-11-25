@extends('layouts.app')

@section('title',$script->name.' | jvscript.fr')

@section('javascript')
<script type="text/javascript">
    $('[data-toggle=confirmation]').confirmation();
    $(function() {
        $('[data-toggle="tooltip"]').tooltip()
    });
</script>
@endsection

@section('content')
@include('global.item-show', ['item' => 'script', 'script' => $script])
@endsection
