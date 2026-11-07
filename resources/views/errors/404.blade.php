@extends('errors.layout')

@section('code', '404')
@section('title', 'No encontramos esta página')
@section('message', 'Puede que el enlace esté mal escrito o que lo que buscas ya no exista.')

@section('actions')
    <a class="boton boton-principal" href="{{ $inicioUrl }}">{{ $inicioTexto }}</a>
    <button type="button" class="boton boton-secundario" onclick="history.back()">Volver</button>
@endsection
