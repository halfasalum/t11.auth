<?php
// app/Http/Controllers/Api/V2/SqlQueryController.php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SqlQueryController extends BaseController
{
    protected $allowedCommands = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'];
    protected $forbiddenKeywords = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'ALTER', 'TRUNCATE', 'CREATE', 'GRANT', 'REVOKE', 'RENAME'];

    public function __construct()
    {
        //$this->middleware('auth:sanctum');
    }

    /**
     * Execute SQL query
     */
    public function execute(Request $request)
    {
        // Get the query string correctly from the request
        $query = $request->input('query');
        $limit = (int) $request->input('limit', 1000);

        // Validate
        if (empty($query) || !is_string($query)) {
            return response()->json([
                'success' => false,
                'message' => 'Query parameter is required and must be a string'
            ], 422);
        }

        // Clean the query: remove trailing semicolons and trim whitespace
        $query = trim($query);
        $query = rtrim($query, ';'); // Remove trailing semicolon

        if (strlen($query) < 4) {
            return response()->json([
                'success' => false,
                'message' => 'Query is too short'
            ], 422);
        }

        // Security: Check for dangerous operations
        if (!$this->isQuerySafe($query)) {
            $this->logQuery($query, 'error', 'Forbidden operation detected');
            return response()->json([
                'success' => false,
                'message' => 'Only SELECT, SHOW, DESCRIBE, and EXPLAIN queries are allowed for security reasons.'
            ], 403);
        }

        // Add limit for SELECT queries if not specified
        $originalQuery = $query;
        $query = $this->addLimitIfNeeded($query, $limit);

        $startTime = microtime(true);

        try {
            $results = DB::select($query);
            $executionTime = (microtime(true) - $startTime) * 1000; // in milliseconds

            // Log successful query
            $this->logQuery($originalQuery, 'success', null, count($results), $executionTime);

            // Format results for display
            $formattedResults = $this->formatResults($results);

            return response()->json([
                'success' => true,
                'data' => [
                    'results' => $formattedResults['data'],
                    'columns' => $formattedResults['columns'],
                    'row_count' => count($results),
                    'execution_time' => round($executionTime, 2),
                    'query' => $query,
                ]
            ]);
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            // Log error
            $this->logQuery($originalQuery, 'error', $e->getMessage(), 0, $executionTime);

            return response()->json([
                'success' => false,
                'message' => 'Query execution failed: ' . $e->getMessage(),
                'error_details' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get query execution history
     */
    public function getHistory(Request $request)
    {
        try {
            $limit = (int) $request->input('limit', 50);

            // Check if table exists
            $tableExists = DB::select("SHOW TABLES LIKE 'query_execution_logs'");

            if (empty($tableExists)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $history = DB::table('query_execution_logs')
                ->where('user_id', $this->getUserId())
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }
    }

    /**
     * Get table list
     */
    public function getTables()
    {
        try {
            $tables = DB::select('SHOW TABLES');

            if (empty($tables)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            // Get the table key name dynamically
            $firstTable = (array) $tables[0];
            $tableKey = array_keys($firstTable)[0];

            $tableNames = [];
            foreach ($tables as $table) {
                $tableArray = (array) $table;
                $tableNames[] = $tableArray[$tableKey];
            }

            // Get row counts for each table
            $tableInfo = [];
            foreach ($tableNames as $table) {
                try {
                    $count = DB::table($table)->count();
                    $tableInfo[] = [
                        'name' => $table,
                        'rows' => $count,
                    ];
                } catch (\Exception $e) {
                    $tableInfo[] = [
                        'name' => $table,
                        'rows' => 0,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $tableInfo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tables: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get table schema
     */
    public function getTableSchema($table)
    {
        try {
            // Sanitize table name
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

            $columns = DB::select("DESCRIBE `{$table}`");
            $indexes = DB::select("SHOW INDEX FROM `{$table}`");

            return response()->json([
                'success' => true,
                'data' => [
                    'columns' => $columns,
                    'indexes' => $indexes,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Table not found or error: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get query suggestions/autocomplete
     */
    public function getSuggestions(Request $request)
    {
        $search = $request->input('search', '');
        $table = $request->input('table', '');

        $suggestions = [
            'keywords' => [
                'SELECT',
                'FROM',
                'WHERE',
                'JOIN',
                'LEFT JOIN',
                'RIGHT JOIN',
                'INNER JOIN',
                'GROUP BY',
                'ORDER BY',
                'HAVING',
                'LIMIT',
                'AND',
                'OR',
                'COUNT',
                'SUM',
                'AVG',
                'MIN',
                'MAX',
                'DISTINCT',
                'AS'
            ],
            'tables' => [],
            'columns' => []
        ];

        try {
            // Get tables for suggestions
            $tablesResult = DB::select('SHOW TABLES');

            if (!empty($tablesResult)) {
                $firstTable = (array) $tablesResult[0];
                $tableKey = array_keys($firstTable)[0];

                $allTables = [];
                foreach ($tablesResult as $t) {
                    $tableArray = (array) $t;
                    $allTables[] = $tableArray[$tableKey];
                }

                // Filter tables
                if ($search) {
                    $suggestions['tables'] = array_values(array_filter($allTables, function ($t) use ($search) {
                        return stripos($t, $search) !== false;
                    }));
                } else {
                    $suggestions['tables'] = array_slice($allTables, 0, 10);
                }
            }

            // Get columns from a specific table if requested
            if ($table) {
                $sanitizedTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
                $columns = DB::select("DESCRIBE `{$sanitizedTable}`");
                $suggestions['columns'] = array_column($columns, 'Field');
            }
        } catch (\Exception $e) {
            // Ignore errors for suggestions
        }

        return response()->json([
            'success' => true,
            'data' => $suggestions
        ]);
    }

    /**
     * Validate if query is safe to execute
     */
    private function isQuerySafe($query): bool
    {
        $queryUpper = strtoupper($query);

        // Check for forbidden keywords
        foreach ($this->forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $queryUpper)) {
                return false;
            }
        }

        // Check if query starts with allowed command
        $isAllowed = false;
        foreach ($this->allowedCommands as $command) {
            if (str_starts_with($queryUpper, $command)) {
                $isAllowed = true;
                break;
            }
        }

        return $isAllowed;
    }

    /**
     * Add limit to SELECT queries if not present
     */
    private function addLimitIfNeeded($query, $limit)
    {
        $queryUpper = strtoupper($query);

        // Only add LIMIT to SELECT queries that don't already have a LIMIT
        if (str_starts_with($queryUpper, 'SELECT') && !preg_match('/\bLIMIT\b/i', $queryUpper)) {
            // Check if there's already a semicolon at the end (should have been removed)
            $query = rtrim($query, ';');
            $query .= " LIMIT {$limit}";
        }

        return $query;
    }

    /**
     * Format results for JSON response
     */
    private function formatResults($results)
    {
        if (empty($results)) {
            return ['data' => [], 'columns' => []];
        }

        // Convert first result to array to get columns
        $firstResult = (array) $results[0];
        $columns = array_keys($firstResult);

        // Convert all results to arrays and handle special types
        $data = array_map(function ($item) {
            $array = (array) $item;
            // Convert any objects to strings
            foreach ($array as $key => $value) {
                if (is_object($value)) {
                    if (method_exists($value, '__toString')) {
                        $array[$key] = $value->__toString();
                    } else {
                        $array[$key] = json_encode($value);
                    }
                }
                // Handle null values
                if ($value === null) {
                    $array[$key] = 'NULL';
                }
            }
            return $array;
        }, $results);

        return [
            'data' => $data,
            'columns' => $columns
        ];
    }

    /**
     * Log query execution
     */
    private function logQuery($query, $status, $errorMessage = null, $rowCount = 0, $executionTime = 0)
    {
        try {
            // Check if table exists first
            $tableExists = DB::select("SHOW TABLES LIKE 'query_execution_logs'");

            if (empty($tableExists)) {
                return; // Skip logging if table doesn't exist
            }

            $queryType = strtoupper(strtok(trim($query), ' '));

            DB::table('query_execution_logs')->insert([
                'user_id' => $this->getUserId(),
                'query' => $query,
                'query_type' => $queryType,
                'row_count' => $rowCount,
                'execution_time' => $executionTime,
                'status' => $status,
                'error_message' => $errorMessage,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log query: ' . $e->getMessage());
        }
    }
}
