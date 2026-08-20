<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\Support\Str;
use App\Core\View\ViewRenderer;
use App\Repositories\OrganizationRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;
use App\Validation\Validator;

/**
 * Organigramma: ruoli e persone del direttivo.
 *
 * Tutto modificabile senza toccare il codice, come richiesto: quando cambia il
 * consiglio, il gruppo aggiorna la pagina da solo.
 */
final class OrganizationController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly OrganizationRepository $organization,
        private readonly SimpleImageService $images,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('organization.manage');

        return $this->render('admin/organization/index.twig', [
            'seo' => $this->seo('Organizzazione')->withNoindex(),
            'roles' => $this->organization->roles(),
            'members' => $this->organization->allMembers(),
            'roleOptions' => $this->organization->roleOptions(),
        ]);
    }

    // --- Ruoli ------------------------------------------------------------

    public function storeRole(Request $request): Response
    {
        $this->authorize('organization.manage');

        $validator = Validator::make($request->all())
            ->required('name', 'Il nome del ruolo')->max('name', 100, 'Il nome del ruolo')
            ->optional('description')->max('description', 300, 'La descrizione')
            ->integer('sort_order', 'L ordinamento', 0, 999);

        if ($validator->fails()) {
            return $this->backWithErrors($request, $request->all(), $validator->errors(), $this->url->route('admin.organization.index'));
        }

        $data = $validator->validatedData();

        $this->organization->createRole([
            'name' => (string) $data['name'],
            'slug' => Str::slug((string) $data['name']),
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $this->success('Ruolo creato.');

        return $this->redirectToRoute('admin.organization.index');
    }

    public function updateRole(Request $request): Response
    {
        $this->authorize('organization.manage');

        $id = $request->routeInt('roleId');

        if ($this->organization->findRole($id) === null) {
            $this->notFound('Ruolo non trovato.');
        }

        $this->organization->updateRole($id, [
            'name' => $request->string('name'),
            'description' => $request->nullableString('description'),
            'sort_order' => $request->int('sort_order'),
        ]);

        $this->success('Ruolo aggiornato.');

        return $this->redirectToRoute('admin.organization.index');
    }

    public function destroyRole(Request $request): Response
    {
        $this->authorize('organization.manage');

        // Le persone collegate restano: perdono solo il riferimento al ruolo.
        $this->organization->deleteRole($request->routeInt('roleId'));
        $this->success('Ruolo eliminato. Le persone collegate restano nell\'elenco.');

        return $this->redirectToRoute('admin.organization.index');
    }

    // --- Persone ----------------------------------------------------------

    public function storeMember(Request $request): Response
    {
        $this->authorize('organization.manage');

        $validated = $this->validateMember($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.organization.index'));
        }

        $data = $this->memberData($request, $validated);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');

            if ($file !== null) {
                $result = $this->images->store($file, MediaPaths::COLLECTION_MEMBERS);

                if ($result['error'] !== null) {
                    $this->warning($result['error']);
                } else {
                    $data['photo_key'] = $result['key'];
                    $data['photo_extension'] = $result['extension'];
                }
            }
        }

        $id = $this->organization->createMember($data);

        $this->audit->log(AuditLogger::CONTENT_CREATED, 'member', $id, sprintf('Persona aggiunta: %s', $data['full_name']));
        $this->success('Persona aggiunta all\'organigramma.');

        return $this->redirectToRoute('admin.organization.index');
    }

    public function updateMember(Request $request): Response
    {
        $this->authorize('organization.manage');

        $id = $request->routeInt('memberId');
        $member = $this->organization->findMember($id);

        if ($member === null) {
            $this->notFound('Persona non trovata.');
        }

        $validated = $this->validateMember($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.organization.index'));
        }

        $data = $this->memberData($request, $validated);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');

            if ($file !== null) {
                $result = $this->images->store($file, MediaPaths::COLLECTION_MEMBERS, $member->photoKey, $member->photoExtension);

                if ($result['error'] !== null) {
                    $this->warning($result['error']);
                } else {
                    $data['photo_key'] = $result['key'];
                    $data['photo_extension'] = $result['extension'];
                }
            }
        }

        if ($request->bool('remove_photo') && $member->photoKey !== null) {
            $this->images->delete(MediaPaths::COLLECTION_MEMBERS, $member->photoKey, $member->photoExtension);
            $data['photo_key'] = null;
            $data['photo_extension'] = null;
        }

        $this->organization->updateMember($id, $data);
        $this->success('Dati aggiornati.');

        return $this->redirectToRoute('admin.organization.index');
    }

    public function destroyMember(Request $request): Response
    {
        $this->authorize('organization.manage');

        $id = $request->routeInt('memberId');
        $member = $this->organization->findMember($id);

        if ($member === null) {
            $this->notFound('Persona non trovata.');
        }

        $photoKey = $this->organization->deleteMember($id);

        if ($photoKey !== null) {
            $this->images->delete(MediaPaths::COLLECTION_MEMBERS, $photoKey, $member->photoExtension);
        }

        $this->audit->log(AuditLogger::CONTENT_DELETED, 'member', $id, sprintf('Persona rimossa: %s', $member->fullName));
        $this->success('Persona rimossa dall\'organigramma.');

        return $this->redirectToRoute('admin.organization.index');
    }

    /** @return array<string, mixed>|null */
    private function validateMember(Request $request): ?array
    {
        $validator = Validator::make($request->all())
            ->required('full_name', 'Il nome')->max('full_name', 120, 'Il nome')
            ->optional('role_title')->max('role_title', 120, 'Il titolo')
            ->optional('bio')->max('bio', 600, 'La biografia')
            ->optional('email')->email('email', 'L\'indirizzo email')
            ->optional('phone')->phone('phone', 'Il telefono')
            ->integer('member_since', 'L\'anno di ingresso', 1900, (int) date('Y'))
            ->integer('sort_order', 'L ordinamento', 0, 999);

        if ($validator->fails()) {
            $this->session->flashInput($request->all());
            $this->session->flashErrors($validator->errors());
            $this->error('Controlla i campi segnalati e riprova.');

            return null;
        }

        return $validator->validatedData();
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function memberData(Request $request, array $validated): array
    {
        return [
            'role_id' => $request->nullableInt('role_id'),
            'full_name' => (string) $validated['full_name'],
            'role_title' => $validated['role_title'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'member_since' => $validated['member_since'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_visible' => $request->bool('is_visible') ? 1 : 0,
        ];
    }
}
