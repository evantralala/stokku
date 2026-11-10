@extends('errors.layout')

@section('kode', '403')
@section('judul', 'Akses Ditolak')
@section('pesan', $exception?->getMessage() ?: 'Akun Anda tidak punya izin untuk membuka halaman ini.')
