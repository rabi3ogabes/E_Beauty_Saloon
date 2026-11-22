<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform marketing site (central domain) — section-based CMS.
        Schema::create('platform_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // home | pricing | help | features-booking | legal-terms | custom...
            $table->json('title');
            $table->json('sections')->nullable(); // [{type, data:{en:{...}, ar:{...}, ...}}]
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->string('og_image_path')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('in_footer')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('body')->nullable(); // HTML (rich editor) per locale
            $table->string('cover_path')->nullable();
            $table->string('author_name')->nullable();
            $table->json('tags')->nullable();
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        // Salon websites (tenant-owned).
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('slug'); // home | about | ...
            $table->json('title');
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->string('og_image_path')->nullable();
            $table->boolean('is_home')->default(false);
            $table->boolean('is_published')->default(true);
            $table->boolean('in_menu')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('site_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('site_page_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // hero|services|team|gallery|studio_cta|shop|packages|reviews|map|instagram|faq|contact|text
            $table->json('content')->nullable(); // per locale: {"en": {...}, "ar": {...}}
            $table->json('settings')->nullable(); // shared: images, limits, urls...
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['site_page_id', 'sort']);
        });

        // PWA web push subscriptions (salon site visitors / clients).
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreignId('client_id')->nullable()->index();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding', 20)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['push_subscriptions', 'site_sections', 'site_pages', 'blog_posts', 'platform_pages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
