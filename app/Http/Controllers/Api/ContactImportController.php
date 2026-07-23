<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailListImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactImportController extends Controller
{
    public function store(Request $request, EmailListImporter $importer): JsonResponse
    {
        $request->validate([
            'emails' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'mimes:txt,csv', 'max:2048'],
            // queue يُقرأ عبر $request->boolean() اللي بيفهم 1/0/true/false/yes/on — بلا قاعدة صارمة
        ]);

        $text = (string) $request->input('emails', '');

        if ($request->hasFile('file')) {
            $text .= "\n".$request->file('file')->get();
        }

        if (trim($text) === '') {
            return response()->json(['message' => 'لا توجد إيميلات'], 422);
        }

        $stats = $importer->import($text, $request->boolean('queue', true));

        return response()->json(['ok' => true, 'stats' => $stats]);
    }
}
