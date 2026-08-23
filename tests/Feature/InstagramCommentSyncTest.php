<?php
use App\Enums\SocialPlatform;
use App\Models\{AccountMedia, MediaComment, SocialAccount};
use App\Services\Publishing\InstagramCommentSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

function commentSync(): InstagramCommentSync { return app(InstagramCommentSync::class); }

it('extracts the shortcode from every permalink shape', function () {
    $s = commentSync();
    expect($s->shortcodeFrom('https://www.instagram.com/p/DaPKBHNknbq/'))->toBe('DaPKBHNknbq');
    expect($s->shortcodeFrom('https://instagram.com/reel/AbC-1_9/?igsh=x'))->toBe('AbC-1_9');
    expect($s->shortcodeFrom('https://www.instagram.com/tv/XyZ123/'))->toBe('XyZ123');
    expect($s->shortcodeFrom('https://www.instagram.com/reels/QqQ_9/'))->toBe('QqQ_9');
    expect($s->shortcodeFrom(null))->toBeNull();
    expect($s->shortcodeFrom('https://example.com/nope'))->toBeNull();
});

it('stores comments from the viewer response, mapped correctly', function () {
    Http::fake([
        '*/api/ins/post-comment/comments/*' => Http::response([
            'code' => 0,
            'data' => [
                'comment_count' => 2,
                'comments' => [
                    [
                        'comment_time' => '2026-07-21 17:15:53',
                        'pk' => '18101159662934663',
                        'text' => 'kasiii pahammmm 🔥',
                        'comment_like_count' => 1,
                        'child_comment_count' => 0,
                        'media_user_dto' => [
                            'username' => 'aayulstrr', 'full_name' => '',
                            'is_verified' => false, 'profile_pic_url' => 'https://cdn/x.jpg',
                        ],
                    ],
                    [
                        'comment_time' => '2026-07-21 16:33:24',
                        'pk' => '18031910981833236',
                        'text' => 'Yuhuu semangattt',
                        'comment_like_count' => 5,
                        'child_comment_count' => 1,
                        'media_user_dto' => [
                            'username' => 'itstina', 'full_name' => 'Tina',
                            'is_verified' => true, 'profile_pic_url' => 'https://cdn/y.jpg',
                        ],
                    ],
                ],
                'pagination_token' => null,
            ],
            'message' => 'success',
        ]),
    ]);

    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'cmt-acc'],
        ['name' => 'IG Komentar', 'access_token' => 'x', 'is_active' => true],
    );
    $media = AccountMedia::create([
        'social_account_id' => $account->id, 'external_id' => 'm1',
        'permalink' => 'https://www.instagram.com/p/DaPKBHNknbq/', 'posted_at' => now(),
    ]);

    $stored = commentSync()->syncMedia($media);

    expect($stored)->toBe(2);
    expect($media->comments()->count())->toBe(2);

    $verified = MediaComment::where('username', 'itstina')->first();
    expect($verified->is_verified)->toBeTrue();
    expect($verified->full_name)->toBe('Tina');
    expect($verified->like_count)->toBe(5);
    expect($verified->reply_count)->toBe(1);
    expect($verified->external_id)->toBe('18031910981833236');

    // Empty full_name is normalised to null.
    expect(MediaComment::where('username', 'aayulstrr')->value('full_name'))->toBeNull();
});

it('is idempotent — re-syncing updates rather than duplicates', function () {
    Http::fake([
        '*/comments/*' => Http::response([
            'code' => 0,
            'data' => ['comments' => [[
                'comment_time' => '2026-07-21 10:00:00', 'pk' => 'p1', 'text' => 'awal',
                'comment_like_count' => 0, 'media_user_dto' => ['username' => 'a'],
            ]]],
        ]),
    ]);

    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'idem-acc'],
        ['name' => 'Idem', 'access_token' => 'x', 'is_active' => true],
    );
    $media = AccountMedia::create([
        'social_account_id' => $account->id, 'external_id' => 'm2',
        'permalink' => 'https://www.instagram.com/p/Zzz/', 'posted_at' => now(),
    ]);

    commentSync()->syncMedia($media);
    commentSync()->syncMedia($media);

    expect($media->comments()->count())->toBe(1);
});

it('swallows a failed viewer response without throwing', function () {
    Http::fake(['*/comments/*' => Http::response(['code' => 500, 'message' => 'nope'], 200)]);

    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'fail-acc'],
        ['name' => 'Fail', 'access_token' => 'x', 'is_active' => true],
    );
    $media = AccountMedia::create([
        'social_account_id' => $account->id, 'external_id' => 'm3',
        'permalink' => 'https://www.instagram.com/p/Fff/', 'posted_at' => now(),
    ]);

    expect(commentSync()->syncMedia($media))->toBe(0);
    expect($media->comments()->count())->toBe(0);
});
