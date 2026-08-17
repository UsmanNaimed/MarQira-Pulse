<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SitePost;
use App\Models\SiteUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * SiteDataController — receive WordPress user and post snapshots.
 *
 * POST /api/v1/sites/users
 * POST /api/v1/sites/posts
 *
 * Protected by HMAC authentication middleware.
 */
class SiteDataController extends Controller
{
    /**
     * Receive a batch of WordPress user snapshots.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function receiveUsers(Request $request)
    {
        $site = $request->attributes->get('site');

        if (!$site) {
            return response()->json(['error' => 'Site not found in request context'], 500);
        }

        $validator = Validator::make($request->all(), [
            'snapshot_at' => 'required|date',
            'users' => 'required|array|min:1|max:1000',
            'users.*.wp_user_id' => 'required|integer|min:1',
            'users.*.user_login' => 'required|string|max:60',
            'users.*.user_email' => 'nullable|email|max:100',
            'users.*.display_name' => 'nullable|string|max:250',
            'users.*.user_registered' => 'nullable|date',
            'users.*.roles' => 'nullable|array',
            'users.*.last_login_at' => 'nullable|date',
            'users.*.metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $snapshotAt = $request->input('snapshot_at');
            $inserted = 0;

            foreach ($request->input('users') as $userData) {
                SiteUser::create([
                    'site_id' => $site->id,
                    'organization_id' => $site->organization_id,
                    'snapshot_at' => $snapshotAt,
                    'wp_user_id' => $userData['wp_user_id'],
                    'user_login' => $userData['user_login'],
                    'user_email' => $userData['user_email'] ?? null,
                    'display_name' => $userData['display_name'] ?? null,
                    'user_registered' => $userData['user_registered'] ?? null,
                    'roles' => $userData['roles'] ?? null,
                    'last_login_at' => $userData['last_login_at'] ?? null,
                    'metadata' => $userData['metadata'] ?? null,
                    'created_at' => now(),
                ]);
                $inserted++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'inserted' => $inserted,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'User data processing failed',
                'message' => 'An error occurred while processing user snapshots.',
            ], 500);
        }
    }

    /**
     * Receive a batch of WordPress post/page snapshots.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function receivePosts(Request $request)
    {
        $site = $request->attributes->get('site');

        if (!$site) {
            return response()->json(['error' => 'Site not found in request context'], 500);
        }

        $validator = Validator::make($request->all(), [
            'snapshot_at' => 'required|date',
            'posts' => 'required|array|min:1|max:1000',
            'posts.*.wp_post_id' => 'required|integer|min:1',
            'posts.*.post_type' => 'required|string|max:20',
            'posts.*.post_status' => 'nullable|string|max:20',
            'posts.*.post_title' => 'nullable|string',
            'posts.*.post_date' => 'nullable|date',
            'posts.*.post_modified' => 'nullable|date',
            'posts.*.post_author_id' => 'nullable|integer',
            'posts.*.post_author_name' => 'nullable|string|max:250',
            'posts.*.guid' => 'nullable|string',
            'posts.*.metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $snapshotAt = $request->input('snapshot_at');
            $inserted = 0;

            foreach ($request->input('posts') as $postData) {
                SitePost::create([
                    'site_id' => $site->id,
                    'organization_id' => $site->organization_id,
                    'snapshot_at' => $snapshotAt,
                    'wp_post_id' => $postData['wp_post_id'],
                    'post_type' => $postData['post_type'],
                    'post_status' => $postData['post_status'] ?? null,
                    'post_title' => $postData['post_title'] ?? null,
                    'post_date' => $postData['post_date'] ?? null,
                    'post_modified' => $postData['post_modified'] ?? null,
                    'post_author_id' => $postData['post_author_id'] ?? null,
                    'post_author_name' => $postData['post_author_name'] ?? null,
                    'guid' => $postData['guid'] ?? null,
                    'metadata' => $postData['metadata'] ?? null,
                    'created_at' => now(),
                ]);
                $inserted++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'inserted' => $inserted,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'Post data processing failed',
                'message' => 'An error occurred while processing post snapshots.',
            ], 500);
        }
    }
}
