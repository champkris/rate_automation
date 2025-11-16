<?php

namespace App\Http\Controllers;

use App\Services\GmailService;
use Illuminate\Http\Request;
use Exception;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth page
     */
    public function redirect(GmailService $gmailService)
    {
        try {
            $authUrl = $gmailService->getAuthUrl();
            return redirect($authUrl);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to generate auth URL',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function callback(Request $request, GmailService $gmailService)
    {
        try {
            $code = $request->get('code');

            if (!$code) {
                return response()->json([
                    'error' => 'No authorization code provided',
                ], 400);
            }

            $gmailService->authenticate($code);

            return response()->json([
                'message' => 'Successfully authenticated with Gmail',
                'success' => true,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Authentication failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
