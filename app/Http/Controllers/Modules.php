<?php

namespace App\Http\Controllers;

use App\Models\modules as ModelsModules;
use App\Models\modules_controls;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Modules extends Controller
{
    public function register(Request $request)
    {

        try {
            $validated = $request->validate([
                'module_name' => 'bail|required|string|max:255',
                'is_global' => 'required|boolean',
            ]);
            $validated['module_status'] = 1;
            $validated['module_url'] = "/";

            $module = ModelsModules::create($validated);
            $moduleId = $module->id;
            $moduleName = $module->module_name;
            $module_control = new modules_controls();
            $module_control->control_name = "Access " . $moduleName;
            $module_control->module_id = $moduleId;
            $module_control->module_control_status = 1;
            $module_control->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Module created successfully',
                'module' => $module,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function control_register(Request $request)
    {

        try {
            $validated = $request->validate([
                'control_name' => 'bail|required|string|max:255',
                'module_id' => 'required',
            ]);
            $validated['module_control_status'] = 1;
            $module = modules_controls::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Module created successfully',
                'module' => $module,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function listModules()
    {
        $modules = ModelsModules::where('module_status', 1)
            ->select('module_name', 'module_url')
            ->get();
        return response()->json([
            'modules' => $modules
        ]);
    }

    public function getModules()
    {
        $modules = ModelsModules::where('module_status', 1)
            ->select('id', 'module_name', 'module_status', 'created_at')
            ->get();
        return response()->json(
            $modules
        );
    }

    public function listControls()
    {
        $modules = modules_controls::where(['module_control_status' => 1])
            ->select('modules_controls.id', 'modules_controls.control_name', 'modules.module_name', 'modules_controls.created_at')
            ->join('modules', 'modules.id', '=', 'modules_controls.module_id')
            ->where('modules.module_status', 1)
            ->get();
        return response()->json(
            $modules
        );
    }


    public function getControls($module_id = null)
    {
        $modules = modules_controls::where(['module_id' => $module_id, 'module_control_status' => 1])
            ->select('id', 'control_name', 'created_at')
            ->get();
        return response()->json(
            $modules
        );
    }
    public function getModulesControls()
    {
        $modulesWithControls = [];
        $modules = ModelsModules::where('module_status', 1)
            ->select('id', 'module_name')
            ->get();
        if (sizeof($modules) > 0) {
            foreach ($modules as $module) {
                $moduleControls = modules_controls::where(['module_id' => $module->id, 'module_control_status' => 1])
                    ->select('id', 'control_name')
                    ->get();
                $modulesWithControls[] = [
                    'module_name' => $module->module_name,
                    'controls' => $moduleControls
                ];
            }
        }
        return response()->json(
            $modulesWithControls
        );
    }
}
