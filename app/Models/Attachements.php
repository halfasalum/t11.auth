<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attachements extends Model
{
    use HasFactory;

    protected $connection = 'customers_db';
    protected $table = 'customer_attachments';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['customer_id', 'attachment_name', 'attachment_path', 'attachment_extension', 'status','company_id'];
}
