@extends('errors.layout')

@section('code', '500')
@section('title', 'Algo falló de nuestro lado')
@section('message', 'No es tu culpa y no se perdió lo que ya estaba guardado. Espera unos segundos y vuelve a intentarlo.')

@section('actions')
    <button type="button" class="boton boton-principal" onclick="location.reload()">Volver a intentar</button>
    <a class="boton boton-secundario" href="{{ $inicioUrl }}">{{ $inicioTexto }}</a>
@endsection
