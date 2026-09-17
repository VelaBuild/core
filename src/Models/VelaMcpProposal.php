<?php

namespace VelaBuild\Core\Models;

use Illuminate\Database\Eloquent\Model;

class VelaMcpProposal extends Model
{
    protected $table = 'vela_mcp_proposals';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'before_snapshot' => 'array', 'after_snapshot' => 'array'];
}
