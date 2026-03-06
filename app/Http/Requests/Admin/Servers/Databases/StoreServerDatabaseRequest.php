<?php

namespace Pterodactyl\Http\Requests\Admin\Servers\Databases;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Database\Query\Builder;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class StoreServerDatabaseRequest extends AdminFormRequest
{
    /**
     * Resolve the database host driver before validation so rules can
     * conditionally require the remote field for MySQL hosts only.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('database_host_id')) {
            $host = DatabaseHost::find($this->input('database_host_id'));
            if ($host) {
                $this->merge(['_driver' => $host->driver ?? 'mysql']);
            }
        }
    }

    /**
     * Validation rules for database creation.
     *
     * The `remote` field (allowed connection hosts) is required for MySQL
     * database hosts but optional for PostgreSQL, which does not use the
     * MySQL-style user@host grant model.
     */
    public function rules(): array
    {
        $isMysql = ($this->input('_driver', 'mysql')) === 'mysql';

        return [
            'database' => [
                'required',
                'string',
                'min:1',
                'max:24',
                Rule::unique('databases')->where(function (Builder $query) {
                    $query->where('database_host_id', $this->input('database_host_id') ?? 0);
                }),
            ],
            'max_connections' => 'nullable',
            'remote' => $isMysql
                ? 'required|string|regex:/^[0-9%.]{1,15}$/'
                : 'nullable|string|regex:/^[0-9%.]{1,15}$/',
            'database_host_id' => 'required|integer|exists:database_hosts,id',
        ];
    }
}
