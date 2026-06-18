<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EmpresaBibliotecaDocumento extends Model
{
    protected $table = 'empresa_biblioteca_documentos';

    protected $fillable = [
        'empresa_id',
        'tipo_id',
        'nome',
        'arquivo_path',
        'arquivo_nome',
        'tamanho_kb',
    ];

    protected $casts = [
        'tamanho_kb' => 'integer',
    ];

    protected $appends = ['arquivo_url'];

    public function getArquivoUrlAttribute(): ?string
    {
        return $this->arquivo_path ? Storage::disk('public')->url($this->arquivo_path) : null;
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function tipo()
    {
        return $this->belongsTo(EmpresaBibliotecaTipo::class, 'tipo_id');
    }
}
