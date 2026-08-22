<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Repositories\OrganizationRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Media\MediaPaths;
use App\Services\Media\SimpleImageService;
use App\Validation\Validator;

/**
 * Il direttivo: chi c'e e cosa fa.
 *
 * Di ogni persona si chiedono quattro cose - nome, cognome, ruolo e una
 * fotografia facoltativa - perche sono le quattro che compaiono in "Chi
 * siamo". Il ruolo si scrive: era una tendina da riempire prima di poter
 * aggiungere qualcuno, cioe un lavoro da fare per poterne fare un altro.
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
            'members' => $this->organization->allMembers(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('organization.manage');

        return $this->render('admin/organization/form.twig', [
            'seo' => $this->seo('Nuova persona')->withNoindex(),
            'member' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('organization.manage');

        $validated = $this->validateMember($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.organization.create'));
        }

        $data = $this->memberData($validated);
        $data += $this->fotografiaCaricata($request);

        $id = $this->organization->createMember($data);

        $this->audit->log(
            AuditLogger::CONTENT_CREATED,
            'member',
            $id,
            sprintf('Persona aggiunta: %s %s', $data['first_name'], $data['last_name']),
        );

        $this->success('Persona aggiunta.');

        return $this->redirectToRoute('admin.organization.index');
    }

    public function edit(Request $request): Response
    {
        $this->authorize('organization.manage');

        $member = $this->organization->findMember($request->routeInt('id'));

        if ($member === null) {
            $this->notFound('Persona non trovata.');
        }

        return $this->render('admin/organization/form.twig', [
            'seo' => $this->seo('Modifica persona')->withNoindex(),
            'member' => $member,
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('organization.manage');

        $id = $request->routeInt('id');
        $member = $this->organization->findMember($id);

        if ($member === null) {
            $this->notFound('Persona non trovata.');
        }

        $validated = $this->validateMember($request);

        if ($validated === null) {
            return $this->back($request, $this->url->route('admin.organization.edit', ['id' => $id]));
        }

        $data = $this->memberData($validated);
        $data += $this->fotografiaCaricata($request, $member->photoKey, $member->photoExtension);

        /*
         * Togliere la fotografia e una scelta a se: chi non ne carica una
         * nuova non sta chiedendo di cancellare quella che c'e.
         */
        if ($request->bool('remove_photo') && $member->photoKey !== null && ! isset($data['photo_key'])) {
            $this->images->delete(MediaPaths::COLLECTION_MEMBERS, $member->photoKey, $member->photoExtension);
            $data['photo_key'] = null;
            $data['photo_extension'] = null;
        }

        $this->organization->updateMember($id, $data);
        $this->success('Persona aggiornata.');

        return $this->redirectToRoute('admin.organization.index');
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('organization.manage');

        $id = $request->routeInt('id');
        $member = $this->organization->findMember($id);

        if ($member === null) {
            $this->notFound('Persona non trovata.');
        }

        $photoKey = $this->organization->deleteMember($id);

        if ($photoKey !== null) {
            $this->images->delete(MediaPaths::COLLECTION_MEMBERS, $photoKey, $member->photoExtension);
        }

        $this->audit->log(
            AuditLogger::CONTENT_DELETED,
            'member',
            $id,
            sprintf('Persona rimossa: %s', $member->fullName()),
        );

        $this->success('Persona rimossa.');

        return $this->redirectToRoute('admin.organization.index');
    }

    // -----------------------------------------------------------------------
    //  Supporto
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function validateMember(Request $request): ?array
    {
        $validator = Validator::make($request->all())
            ->required('first_name', 'Il nome')->max('first_name', 60, 'Il nome')
            ->required('last_name', 'Il cognome')->max('last_name', 60, 'Il cognome')
            ->required('role', 'Il ruolo')->max('role', 120, 'Il ruolo');

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
    private function memberData(array $validated): array
    {
        return [
            'first_name' => (string) $validated['first_name'],
            'last_name' => (string) $validated['last_name'],
            'role' => (string) $validated['role'],
        ];
    }

    /**
     * La fotografia appena caricata, se ce n'e una e se e passata.
     *
     * @return array<string, mixed> Vuoto quando non c'e niente da cambiare.
     */
    private function fotografiaCaricata(
        Request $request,
        ?string $chiaveEsistente = null,
        ?string $estensioneEsistente = null,
    ): array {
        if (! $request->hasFile('photo')) {
            return [];
        }

        $file = $request->file('photo');

        if ($file === null) {
            return [];
        }

        $esito = $this->images->store($file, MediaPaths::COLLECTION_MEMBERS, $chiaveEsistente, $estensioneEsistente);

        if ($esito['error'] !== null) {
            $this->warning($esito['error']);

            return [];
        }

        return [
            'photo_key' => $esito['key'],
            'photo_extension' => $esito['extension'],
        ];
    }
}
