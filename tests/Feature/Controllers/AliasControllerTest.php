<?php

namespace Tests\Feature\Controllers;

use App\Alias;
use App\Domain;
use App\Http\Resources\AliasResource;
use App\Http\Resources\MailboxResource;
use App\Mailbox;
use function array_except;
use function array_key_exists;
use function array_merge;
use function compact;
use function csrf_token;
use function factory;
use function GuzzleHttp\Psr7\mimetype_from_extension;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AliasControllerTest extends TestCase
{
    use WithFaker;
    use RefreshDatabase;

    private $admin;

    private $mailbox;

    protected function setUp()
    {
        parent::setUp();
        factory(Domain::class)->create();
        factory(Mailbox::class)->create();
        $this->admin = factory(Mailbox::class)->create([
            'active'         => true,
            'is_super_admin' => true
        ]);
        $this->mailbox = factory(Mailbox::class)->create([
            'active'         => true,
            'is_super_admin' => false
        ]);
    }

    /**
     * Test pagination
     */
    public function testIndex()
    {
        $alias = factory(Alias::class)->create();
        $aliases = factory(Alias::class, $alias->getPerPage() - 1)->create();
        $localParts1 = array_merge([$alias->local_part], $aliases->pluck('local_part')
            ->toArray());
        $localParts2 = factory(Alias::class, $alias->getPerPage())
            ->create()
            ->pluck('local_part')
            ->toArray();
        $response = $this->actingAs($this->admin)
            ->get(route('aliases.index'));
        $response->assertSuccessful();
        $response->assertSeeTextInOrder($localParts1);
        foreach ($localParts2 as $value) {
            $response->assertDontSeeText($value);
        }
        $response2 = $this->actingAs($this->admin)
            ->get(route('aliases.index', ['page' => '2']));
        $response2->assertSuccessful();
        $response2->assertSeeTextInOrder($localParts2);
        foreach ($localParts1 as $value) {
            $response2->assertDontSeeText($value);
        }
    }

    public function testIndexJson()
    {
        $perPage = (new Alias())->getPerPage();
        $aliases1 = factory(Alias::class, $perPage)->create(['active' => 1]);
        $aliases2 = factory(Alias::class, $perPage)->create(['active' => 1]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('aliases.index'));
        $response->assertSuccessful();

        $response2 = $this->actingAs($this->admin)
            ->getJson(route('aliases.index', ['page' => '2']));
        $response2->assertSuccessful();

        $aliases1->each(function (Alias $alias) use ($response, $response2) {
            $response->assertJsonFragment((new AliasResource($alias))->jsonSerialize());
            $response2->assertJsonMissingExact((new AliasResource($alias))->jsonSerialize());
        });

        $aliases2->each(function (Alias $alias) use ($response, $response2) {
            $response2->assertJsonFragment((new AliasResource($alias))->jsonSerialize());
            $response->assertJsonMissingExact((new AliasResource($alias))->jsonSerialize());
        });
    }

    public function testCreate()
    {
        $this->get(route('aliases.create'))
            ->assertRedirect(route('login'));

        $this->followingRedirects()
            ->actingAs($this->mailbox)
            ->get(route('aliases.create'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('aliases.create'))
            ->assertSuccessful()
            ->assertViewIs('aliases.create');
    }

    public function testStore()
    {
        Session::start();
        $domain = factory(Domain::class)->create();
        $mailbox = factory(Mailbox::class)->create(['domain_id' => $domain]);
        $data = [
            'local_part'          => $this->faker->unique()->userName,
            'description'         => $this->faker->sentence,
            'domain_id'           => $domain->id,
            'sender_mailboxes'    => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ],
            'recipient_mailboxes' => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ]
        ];
        $this->followingRedirects()
            ->actingAs($this->admin)
            ->post(route('aliases.store'), array_merge($data, ['_token' => csrf_token()]))
            ->assertSuccessful();

        $this->assertDatabaseHas('aliases', array_except($data, [
            'sender_mailboxes',
            'recipient_mailboxes'
        ]));

        /** @var Alias $alias */
        $alias = Alias::query()
            ->where('local_part', $data['local_part'])
            ->where('domain_id', $data['domain_id'])
            ->firstOrFail();
        $this->assertTrue($alias->senderMailboxes()
            ->where('mailbox_id', $mailbox->id)
            ->exists());
        $this->assertTrue($alias->recipientMailboxes()
            ->where('mailbox_id', $mailbox->id)
            ->exists());
    }

    public function testStoreWithDeactivateAt()
    {
        Session::start();
        $domain = factory(Domain::class)->create();
        $mailbox = factory(Mailbox::class)->create();
        $data = [
            'local_part'            => $this->faker->unique()->userName,
            'description'           => $this->faker->sentence,
            'domain_id'             => $domain->id,
            'sender_mailboxes'      => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ],
            'recipient_mailboxes'   => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ],
            'deactivate_at_days'    => 11,
            'deactivate_at_hours'   => 22,
            'deactivate_at_minutes' => 10,
        ];

        $this->followingRedirects()
            ->actingAs($this->admin)
            ->post(route('aliases.store'), array_merge($data, ['_token' => csrf_token()]))
            ->assertSuccessful();

        $this->assertDatabaseHas('aliases', array_except($data, [
            'sender_mailboxes',
            'recipient_mailboxes',
            'deactivate_at_days',
            'deactivate_at_hours',
            'deactivate_at_minutes'
        ]));

        $alias = Alias::query()
            ->where('local_part', $data['local_part'])
            ->where('domain_id', $data['domain_id'])
            ->firstOrFail();
        $this->assertTrue($alias->deactivate_at !== null);
    }

    public function testUpdateActiveOnDeactivateAlias()
    {
        Session::start();
        $mailbox = factory(Mailbox::class)->create();
        $alias = factory(Alias::class)->create([
            'deactivate_at' => '2018-07-14 14:44:58',
            'active'        => false,
        ]);

        $data = [
            'deactivate_at'       => '2018-07-14 14:44:55',
            'active'              => true,
            'sender_mailboxes'    => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ],
            'recipient_mailboxes' => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ],
        ];

        $this->followingRedirects()
            ->actingAs($this->admin)
            ->patch(route('aliases.update', compact('alias')), array_merge($data, ['_token' => csrf_token()]))
            ->assertSuccessful();


        $alias = Alias::query()
            ->where('local_part', $alias->local_part)
            ->where('domain_id', $alias->domain_id)
            ->firstOrFail();

        $this->assertTrue($alias->deactivate_at === null);
        $this->assertTrue($alias->active === 1);
    }

    public function testUpdateLocalPart()
    {
        Session::start();
        $mailbox = factory(Mailbox::class)->create();
        $alias = factory(Alias::class)->create(['local_part' => $this->faker->userName]);

        $data = [
            'local_part'          => $this->faker->userName,
            'sender_mailboxes'    => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ],
            'recipient_mailboxes' => [
                [
                    'id'      => $mailbox->id,
                    'address' => $mailbox->address()
                ]
            ],
        ];

        $this->followingRedirects()
            ->actingAs($this->admin)
            ->patch(route('aliases.update', compact('alias')), array_merge($data, ['_token' => csrf_token()]))
            ->assertSuccessful();

        $alias = Alias::query()
            ->where('local_part', $data['local_part'])
            ->where('domain_id', $alias->domain_id)
            ->firstOrFail();

        $this->assertTrue($alias->local_part === $data['local_part']);
    }

    public function testUpdateExternalRecipients()
    {
        Session::start();
        $mailbox = factory(Mailbox::class)->create();
        $alias = factory(Alias::class)->create();
        $alias->addExternalRecipient($this->faker->email);
        $alias->addExternalRecipient($this->faker->email);
        $alias->senderMailboxes()
            ->save($mailbox);

        $data = [
            'external_recipients' => [
                [
                    'address' => $this->faker->email
                ],
                [
                    'address' => $this->faker->email
                ]
            ]
        ];

        $this->followingRedirects()
            ->actingAs($this->admin)
            ->patch(route('aliases.update', compact('alias')), array_merge($data, ['_token' => csrf_token()]))
            ->assertSuccessful();

        $alias = Alias::query()
            ->where('local_part', $alias->local_part)
            ->where('domain_id', $alias->domain_id)
            ->firstOrFail();

        $recipients = $alias->recipientAddresses()
            ->toArray();

        $this->assertTrue(in_array($data['external_recipients'][0]['address'], $recipients));
        $this->assertTrue(in_array($data['external_recipients'][1]['address'], $recipients));
    }

    public function testShow()
    {
        $alias = factory(Alias::class)->create();
        $response = $this->actingAs($this->admin)
            ->get(route('aliases.show', compact('alias')));
        $response->assertSuccessful();
        $response->assertSeeText($alias->local_part);
    }

    public function testEdit()
    {
        $alias = factory(Alias::class)->create();
        $response = $this->actingAs($this->admin)
            ->get(route('aliases.edit', compact('alias')));
        $response->assertSuccessful();
        $response->assertSeeText($alias->local_part);
    }

    public function testUpdate()
    {
        Session::start();
        /** @var Alias $alias */
        $alias = factory(Alias::class)->create();
        $oldMailbox = factory(Mailbox::class)->create();
        $newMailbox1 = factory(Mailbox::class)->create();
        $newMailbox2 = factory(Mailbox::class)->create();
        $alias->senderMailboxes()
            ->attach($oldMailbox);
        $alias->addRecipientMailbox($oldMailbox);
        $data = [
            'description'         => 'my new description',
            'sender_mailboxes'    => [
                [
                    'id'      => $newMailbox1->id,
                    'address' => $newMailbox1->address()
                ]
            ],
            'recipient_mailboxes' => [
                [
                    'id'      => $newMailbox2->id,
                    'address' => $newMailbox2->address()
                ]
            ]
        ];
        $this->followingRedirects()
            ->actingAs($this->admin)
            ->patch(route('aliases.update', compact('alias')), array_merge($data, ['_token' => csrf_token()]))
            ->assertSuccessful();

        $alias = $alias->fresh();
        $this->assertEquals($alias->description, $data['description']);
        $this->assertTrue($alias->senderMailboxes()
            ->where('mailbox_id', $newMailbox1->id)
            ->exists());
        $this->assertTrue($alias->recipientMailboxes()
            ->where('mailbox_id', $newMailbox2->id)
            ->exists());
    }

    public function testDestroy()
    {
        Session::start();
        /** @var Alias $alias */
        $alias = factory(Alias::class)->create();
        $response = $this->followingRedirects()
            ->actingAs($this->admin)
            ->delete(route('aliases.destroy', compact('alias')), ['_token' => csrf_token()]);
        $response->assertSuccessful();
        $this->assertDatabaseMissing('aliases', $alias->toArray());
    }
}
