@extends('errors.layout')

@php
    /**
     * Solo se muestra el motivo cuando es uno de los nuestros (en español). Los genéricos del framework
     * se reemplazan por una frase clara.
     */
    $motivo = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $genericos = ['', 'This action is unauthorized.', 'Forbidden', 'Unauthorized.'];
    $mensaje = in_array($motivo, $genericos, true)
        ? 'Tu cuenta no tiene permiso para ver esta sección. Si crees que deberías tenerlo, pídeselo al administrador del local.'
        : $motivo;
@endphp

@section('code', '403')
@section('title', 'No tienes acceso a esta sección')
@section('message', $mensaje)

@section('actions')
    <a class="boton boton-principal" href="{{ $inicioUrl }}">{{ $inicioTexto }}</a>
    <button type="button" class="boton boton-secundario" onclick="history.back()">Volver</button>
    {{-- Sin esto, quien no tiene acceso a nada del panel no tendría cómo salir: el inicio lo trae de vuelta aquí. --}}
    @if (request()->hasSession() && auth()->check())
        <form method="POST" action="{{ route('logout') }}" style="display: contents">
            @csrf
            <button type="submit" class="boton boton-secundario">Cerrar sesión</button>
        </form>
    @endif
@endsection
