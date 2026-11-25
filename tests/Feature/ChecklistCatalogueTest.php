<?php

namespace Tests\Feature;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistVersion;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChecklistCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private User $coordinator;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
        $this->coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
    }

    private function workspace(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function signature(): array
    {
        return ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
    }

    private function publishPath(ChecklistVersion $version): string
    {
        return '/workspace/checklists/'.$version->checklist_template_id.'/versions/'.$version->id.'/publish';
    }

    public function test_catalogue_and_mutations_require_operations_permission_in_the_selected_organisation(): void
    {
        $template = ChecklistTemplate::where('organisation_id', $this->organisation->id)->firstOrFail();
        $draft = $template->versions()->where('status', 'draft')->firstOrFail();
        $this->get('/workspace/checklists')->assertRedirect('/login');
        $this->workspace($this->nurse)->get('/workspace/checklists')->assertForbidden();
        $this->postJson('/workspace/checklists', ['title' => 'Unauthorised synthetic draft', 'steps' => ['One action.']])->assertForbidden();
        $this->postJson('/workspace/checklists/'.$template->id.'/versions', ['steps' => ['One action.']])->assertForbidden();
        $this->postJson($this->publishPath($draft), $this->signature())->assertForbidden();

        $foreign = Organisation::create(['name' => 'Other synthetic SOP workspace', 'type' => 'care_service', 'currency' => 'MUR', 'time_zone' => 'Indian/Mauritius']);
        $foreignTemplate = ChecklistTemplate::create(['organisation_id' => $foreign->id, 'title' => 'Other workspace private SOP', 'created_by' => $this->coordinator->id]);
        $foreignVersion = $foreignTemplate->versions()->create(['version' => 1, 'steps' => ['Other workspace synthetic step.'], 'created_by' => $this->coordinator->id]);
        $this->workspace($this->coordinator)->get('/workspace/checklists')->assertInertia(fn (Assert $page) => $page
            ->component('checklists/index', false)->has('templates', 1)
            ->where('templates.0.title', $template->title)
            ->where('templates.0.versions.0.status', 'draft')
            ->where('templates.0.versions.1.status', 'published')
            ->where('templates.0.versions.2.status', 'published'));
        $this->postJson('/workspace/checklists/'.$foreignTemplate->id.'/versions', ['steps' => ['Synthetic revision.']])->assertNotFound();
        $this->postJson($this->publishPath($foreignVersion), $this->signature())->assertNotFound();
        $this->postJson('/workspace/checklists/'.$template->id.'/versions/'.$foreignVersion->id.'/publish', $this->signature())->assertNotFound();
        $this->withSession(['organisation_id' => $foreign->id])->get('/workspace/checklists')->assertForbidden();
    }

    public function test_original_and_revision_requests_are_validated_and_replay_safe(): void
    {
        $this->workspace($this->coordinator)->postJson('/workspace/checklists', ['title' => ' ', 'steps' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'steps']);
        $this->postJson('/workspace/checklists', ['title' => 'Synthetic checklist', 'steps' => ['key' => 'Not a list.']])
            ->assertUnprocessable()->assertJsonValidationErrors('steps');
        $this->postJson('/workspace/checklists', ['title' => 'Synthetic checklist', 'steps' => [str_repeat('x', 1001)]])
            ->assertUnprocessable()->assertJsonValidationErrors('steps.0');
        $create = ['request_id' => Str::uuid()->toString(), 'title' => 'Synthetic operational SOP', 'steps' => ['Confirm the reference.', 'Assign the follow-up task.']];
        $this->post('/workspace/checklists', $create)->assertSessionHasNoErrors();
        $this->post('/workspace/checklists', $create)->assertSessionHasNoErrors();
        $this->postJson('/workspace/checklists', [...$create, 'title' => 'Different synthetic SOP'])->assertConflict();
        $template = ChecklistTemplate::where('request_id', $create['request_id'])->firstOrFail();
        $this->assertSame($create['steps'], $template->versions()->sole()->steps);

        $draftPath = '/workspace/checklists/'.$template->id.'/versions';
        $proposal = ['request_id' => Str::uuid()->toString(), 'steps' => ['Use the synthetic reference.', 'Confirm the assigned owner.']];
        $this->post($draftPath, $proposal)->assertSessionHasNoErrors();
        $this->post($draftPath, $proposal)->assertSessionHasNoErrors();
        $this->postJson($draftPath, [...$proposal, 'steps' => ['Different action.']])->assertConflict();
        $this->assertSame([1, 2], $template->versions()->orderBy('version')->pluck('version')->all());
        $this->assertDatabaseHas('audit_events', ['event' => 'checklist.template.created', 'organisation_id' => $this->organisation->id, 'actor_id' => $this->coordinator->id, 'resource_id' => $template->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'checklist.version.drafted', 'organisation_id' => $this->organisation->id, 'actor_id' => $this->coordinator->id, 'resource_id' => $template->versions()->where('version', 2)->sole()->id]);
    }

    public function test_signed_publication_retains_older_published_snapshots_and_blocks_stale_drafts(): void
    {
        $this->workspace($this->coordinator)->post('/workspace/checklists', ['title' => 'Synthetic publication workflow', 'steps' => ['Retain original operations snapshot.']])
            ->assertSessionHasNoErrors();
        $template = ChecklistTemplate::where('title', 'Synthetic publication workflow')->firstOrFail();
        $first = $template->versions()->sole();
        $this->postJson($this->publishPath($first), ['password' => 'bad', 'declaration' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['password', 'declaration']);
        $this->post($this->publishPath($first), $this->signature())->assertSessionHasNoErrors();
        $first->refresh();
        $this->assertSame($first->digest(), $first->content_hash);
        $draftPath = '/workspace/checklists/'.$template->id.'/versions';
        $this->post($draftPath, ['steps' => ['Older draft action.']])->assertSessionHasNoErrors();
        $this->post($draftPath, ['steps' => ['Newer version workflow action.']])->assertSessionHasNoErrors();
        [$second, $third] = $template->versions()->where('version', '>', 1)->orderBy('version')->get()->all();
        $this->post($this->publishPath($third), $this->signature())->assertSessionHasNoErrors();
        $publishedAt = $third->fresh()->published_at;
        $this->post($this->publishPath($third), $this->signature())->assertSessionHasNoErrors();
        $this->postJson($this->publishPath($second), $this->signature())->assertConflict();
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertEquals($publishedAt, $third->fresh()->published_at);
        $this->assertSame($this->coordinator->id, $third->fresh()->published_by);
        $this->assertSame($first->content_hash, $first->fresh()->content_hash);
        $this->assertSame($first->steps, $first->fresh()->steps);
        $this->assertSame([1, 3], $template->versions()->where('status', 'published')->orderBy('version')->pluck('version')->all());
        $this->assertSame(1, DB::table('audit_events')
            ->where('event', 'checklist.version.published')->where('resource_id', $third->id)->count());
    }
}
