<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DashboardRole extends Model
{
    protected $table = 'dashboard_roles';
    protected $primaryKey = 'slug';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['slug', 'name', 'description', 'page_access', 'is_system'];

    protected function casts(): array
    {
        return ['page_access' => 'array', 'is_system' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'slug');
    }

    public static function pages(): array
    {
        return [
            'overview' => ['label' => 'Executive Overview', 'group' => 'Reports'],
            'metric' => ['label' => 'Activity-Based Costing', 'group' => 'Reports'],
            'equipmentCost' => ['label' => 'Equipment Cost', 'group' => 'Reports'],
            'inventory' => ['label' => 'Inventory Valuation', 'group' => 'Reports'],
            'cost' => ['label' => 'Report Cost (COGP)', 'group' => 'Reports'],
            'variance' => ['label' => 'Cost Variance Analysis', 'group' => 'Reports'],
            'financial' => ['label' => 'Financial Statements', 'group' => 'Reports'],
            'glReview' => ['label' => 'GL Transaction Review', 'group' => 'Transactions'],
            'integration' => ['label' => 'Integration & Data Sources', 'group' => 'Data & Setup'],
            'mapping' => ['label' => 'Allocation Mapping', 'group' => 'Data & Setup'],
            'config' => ['label' => 'Configuration', 'group' => 'Data & Setup'],
        ];
    }
}
