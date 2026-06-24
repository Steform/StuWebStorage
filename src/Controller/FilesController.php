<?php

namespace App\Controller;

use App\Dto\File\FilesPageViewModel;
use App\Dto\File\UserFilesCapabilities;
use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Entity\ShareGrant;
use App\Entity\User;
use App\File\SharedFileOwnerListCriteria;
use App\Repository\PublicDownloadChallengeRepository;
use App\Repository\FolderRepository;
use App\Repository\ShareGrantRepository;
use App\Repository\SharedFileRepository;
use App\Repository\UserRepository;
use App\Service\Admin\AdminGodviewSessionStateService;
use App\Service\Audit\DownloadAuditService;
use App\Service\File\ChunkedUploadService;
use App\Service\File\FilesQueryScopeResolver;
use App\Service\File\UserFilesPaneBuilderService;
use App\Service\File\UserStorageQuotaService;
use App\Service\File\ZipExtractLimitsResolver;
use App\Service\File\ZipExtractService;
use App\Service\Format\BinaryByteFormatter;
use App\Service\File\FileEncryptionService;
use App\Service\Share\FolderPublicTokenService;
use App\Service\Share\FolderShareService;
use App\Service\Share\FolderTreeService;
use App\Service\Share\FolderPropertiesService;
use App\Service\Share\FolderZipService;
use App\Service\Share\FriendsShareService;
use App\Service\Share\PublicShareService;
use App\Service\Share\PublicShareResourcePasswordService;
use App\Service\Share\ShareAuthorizationService;
use App\Service\Share\ZipEntryNameSanitizer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller FilesController.
 */
class FilesController extends AbstractController
{
    private const CSRF_UPLOAD = 'files_upload';

    private const CSRF_DELETE = 'files_delete';

    private const CSRF_VISIBILITY = 'files_visibility';

    private const CSRF_GRANT = 'files_grant';

    private const CSRF_REVOKE = 'files_revoke';

    private const CSRF_SHARE_PUBLIC = 'files_share_public';

    private const CSRF_SHARE_FRIENDS = 'files_share_friends';

    private const CSRF_SHARE_BULK_PUBLIC = 'files_share_bulk_public';

    private const CSRF_SHARE_BULK_FRIENDS = 'files_share_bulk_friends';

    private const CSRF_DELETE_BULK = 'files_delete_bulk';
    private const CSRF_RENAME = 'files_rename';
    private const CSRF_FOLDER_CREATE = 'files_folder_create';
    private const CSRF_FOLDER_DELETE = 'files_folder_delete';
    private const CSRF_FOLDER_SHARE_PUBLIC = 'files_folder_share_public';
    private const CSRF_FOLDER_SHARE_FRIENDS = 'files_folder_share_friends';
    private const CSRF_FOLDER_RENAME = 'files_folder_rename';

    private const CSRF_MOVE_BULK = 'files_move_bulk';

    private const CSRF_EXTRACT = 'files_extract';

    private const MAX_UPLOAD_BYTES = 107374182400;
    private const MAX_TEXT_PREVIEW_BYTES = 20971520;

    /** @var array<int, string> */
    private const LISTING_SORT_FIELDS = ['name', 'size', 'uploaded', 'modified', 'ext', 'type', 'pseudo', 'share_public', 'share_friends'];

    /**
     * Allowlist for GET files_preview: lowercase extension => [mime => string, kind => pdf|video|audio|text].
     * Browser may still fail to decode; server only serves bytes with correct Content-Type.
     * Align Twig preview triggers (listing + dropdowns) with these keys.
     *
     * @var array<string, array{mime: string, kind: string}>
     */
    private const PREVIEW_STREAM_BY_EXTENSION = [
        'pdf' => ['mime' => 'application/pdf', 'kind' => 'pdf'],
        'mp4' => ['mime' => 'video/mp4', 'kind' => 'video'],
        'webm' => ['mime' => 'video/webm', 'kind' => 'video'],
        'ogv' => ['mime' => 'video/ogg', 'kind' => 'video'],
        'mov' => ['mime' => 'video/quicktime', 'kind' => 'video'],
        'mp3' => ['mime' => 'audio/mpeg', 'kind' => 'audio'],
        'mpeg' => ['mime' => 'audio/mpeg', 'kind' => 'audio'],
        'wav' => ['mime' => 'audio/wav', 'kind' => 'audio'],
        'ogg' => ['mime' => 'audio/ogg', 'kind' => 'audio'],
        'm4a' => ['mime' => 'audio/mp4', 'kind' => 'audio'],
        'aac' => ['mime' => 'audio/aac', 'kind' => 'audio'],
        'txt' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'log' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'md' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'markdown' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'json' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'csv' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'tsv' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'xml' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'yml' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'yaml' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'ini' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
        'conf' => ['mime' => 'text/plain; charset=utf-8', 'kind' => 'text'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly ShareGrantRepository $shareGrantRepository,
        private readonly PublicDownloadChallengeRepository $publicDownloadChallengeRepository,
        private readonly UserRepository $userRepository,
        private readonly ShareAuthorizationService $shareAuthorizationService,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly DownloadAuditService $downloadAuditService,
        private readonly PublicShareService $publicShareService,
        private readonly PublicShareResourcePasswordService $publicShareResourcePasswordService,
        private readonly FriendsShareService $friendsShareService,
        private readonly FolderTreeService $folderTreeService,
        private readonly FolderShareService $folderShareService,
        private readonly FolderPublicTokenService $folderPublicTokenService,
        private readonly FolderPropertiesService $folderPropertiesService,
        private readonly FolderZipService $folderZipService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly BinaryByteFormatter $binaryByteFormatter,
        private readonly ChunkedUploadService $chunkedUploadService,
        private readonly ZipExtractService $zipExtractService,
        private readonly ZipExtractLimitsResolver $zipExtractLimitsResolver,
        private readonly UserStorageQuotaService $userStorageQuotaService,
        private readonly FilesQueryScopeResolver $filesQueryScopeResolver,
        private readonly UserFilesPaneBuilderService $userFilesPaneBuilderService,
        private readonly AdminGodviewSessionStateService $adminGodviewSessionStateService,
        private readonly string $projectDir,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @brief List owned/shared files with navbar search and filter state.
     * @param Request $request HTTP request (query drives listing criteria; partial=1 returns listing fragment only).
     * @return Response
     * @date 2026-05-07
     * @author Stephane H.
     */
    #[Route('/files', name: 'files_index', methods: ['GET'])]
    #[IsGranted('ROLE_SHARE')]
    public function index(Request $request): Response
    {
        if ($this->mustRedirectFilesIndexToCanonicalRoot($request)) {
            return $this->redirectToRoute('files_index');
        }

        if ($this->isGranted('ROLE_SHARE') && !$this->isGranted('ROLE_SHARE_SEND')) {
            $scope = strtolower(trim((string) $request->query->get('listing_scope', '')));
            if ($scope === '' || $scope === 'owned' || $scope === 'both') {
                $queryParams = $request->query->all();
                $queryParams['listing_scope'] = 'shared';

                return $this->redirectToRoute('files_index', $queryParams);
            }
        }

        $viewData = $this->buildFilesIndexViewData($request);

        if ($request->query->getBoolean('partial')) {
            return $this->render('files/_listing_fragment.html.twig', $viewData);
        }

        return $this->render('files/index.html.twig', $viewData);
    }

    /**
     * @brief Return true when /files query contains forbidden admin scope controls requiring canonical redirect.
     * @param Request $request HTTP request.
     * @return bool
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function mustRedirectFilesIndexToCanonicalRoot(Request $request): bool
    {
        if ($request->query->has('admin_context') || $request->query->has('admin_view_scope')) {
            return true;
        }

        return strtolower(trim((string) $request->query->get('view_scope', ''))) === 'all';
    }

    /**
     * @brief Compare two filtered listing query maps for equality (keys sorted).
     * @param array<string, mixed> $a First query map.
     * @param array<string, mixed> $b Second query map.
     * @return bool
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function adminFilesAreListingQueriesEqual(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /**
     * @brief Redirect GET /admin/files to a canonical query when admin all-users scope carries stale params (e.g. view_scope=me).
     * @param Request $request HTTP request.
     * @param User $user Authenticated admin user.
     * @return Response|null 302 redirect when normalization is required, otherwise null.
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function maybeRedirectAdminFilesToCanonicalAllUsersQuery(Request $request, User $user): ?Response
    {
        if ($request->query->getBoolean('partial')) {
            return null;
        }
        if (strtolower(trim((string) $request->query->get('admin_view_scope', 'owner'))) !== 'all') {
            return null;
        }
        $scope = $this->filesQueryScopeResolver->resolve($request, $user, true);
        if (($scope['canonicalViewScope'] ?? '') !== 'all') {
            return null;
        }

        $merged = $request->query->all();
        unset($merged['partial']);
        $merged['admin_context'] = '1';
        $merged['admin_view_scope'] = 'all';
        $merged['view_scope'] = 'all';
        foreach (['subject_user', 'owner', 'owner_query', 'folder', 'shared_folder'] as $stripKey) {
            unset($merged[$stripKey]);
        }

        $canonical = $this->filterListingRouteParams($merged);
        $current = $this->filterListingRouteParams($request->query->all());
        if ($this->adminFilesAreListingQueriesEqual($canonical, $current)) {
            return null;
        }

        return $this->redirectToRoute('admin_files_index', $canonical);
    }

    /**
     * @brief Render the files space in admin godview mode.
     * @param Request $request HTTP request.
     * @return Response
     * @date 2026-05-04
     * @author Stephane H.
     */
    #[Route('/admin/files', name: 'admin_files_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminIndex(Request $request): Response
    {
        $request->query->set('admin_context', '1');

        /** @var User $user */
        $user = $this->getUser();

        if (!$request->query->getBoolean('partial')) {
            $sessionRedirect = $this->maybeRedirectAdminGodviewFromSessionMemory($request);
            if ($sessionRedirect instanceof Response) {
                return $sessionRedirect;
            }
        }

        $canonicalRedirect = $this->maybeRedirectAdminFilesToCanonicalAllUsersQuery($request, $user);
        if ($canonicalRedirect instanceof Response) {
            return $canonicalRedirect;
        }

        $viewData = $this->buildFilesIndexViewData($request);

        if (!$request->query->getBoolean('partial') && $this->isAdminGodviewViewScopeQueryPresent($request)) {
            $this->persistAdminGodviewStateFromViewData($request, $viewData);
        }

        if ($request->query->getBoolean('partial')) {
            return $this->render('files/_listing_fragment.html.twig', $viewData);
        }

        return $this->render('files/index.html.twig', $viewData);
    }

    /**
     * @brief True when admin_view_scope is absent from the query string (bare /admin/files entry).
     * @param Request $request Incoming request.
     * @return bool
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function isAdminGodviewViewScopeQueryOmitted(Request $request): bool
    {
        return !$request->query->has('admin_view_scope');
    }

    /**
     * @brief True when admin_view_scope is explicitly provided (owner or all).
     * @param Request $request Incoming request.
     * @return bool
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function isAdminGodviewViewScopeQueryPresent(Request $request): bool
    {
        if (!$request->query->has('admin_view_scope')) {
            return false;
        }
        $raw = strtolower(trim((string) $request->query->get('admin_view_scope', '')));

        return \in_array($raw, ['owner', 'all'], true);
    }

    /**
     * @brief Redirect bare admin files URL to remembered scope or fallback all-users godview.
     * @param Request $request Incoming request (session-backed).
     * @return Response|null 302 when redirecting, null to continue normal flow.
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function maybeRedirectAdminGodviewFromSessionMemory(Request $request): ?Response
    {
        if (!$this->isAdminGodviewViewScopeQueryOmitted($request)) {
            return null;
        }

        if (!$request->hasSession()) {
            $params = $this->filterListingRouteParams([
                'admin_context' => '1',
                'admin_view_scope' => 'all',
                'view_scope' => 'all',
            ]);

            return $this->redirectToRoute('admin_files_index', $params);
        }

        $remembered = $this->adminGodviewSessionStateService->loadRememberedState($request->getSession());
        if ($remembered !== null && $this->adminGodviewSessionStateService->isValidRememberedState($remembered)) {
            $params = $this->filterListingRouteParams(
                $this->adminGodviewSessionStateService->buildRedirectQueryFromState($remembered)
            );

            return $this->redirectToRoute('admin_files_index', $params);
        }

        $params = $this->filterListingRouteParams([
            'admin_context' => '1',
            'admin_view_scope' => 'all',
            'view_scope' => 'all',
        ]);

        return $this->redirectToRoute('admin_files_index', $params);
    }

    /**
     * @brief Persist last successful admin godview listing slice for bare /admin/files recovery.
     * @param Request $request Incoming request.
     * @param array<string, mixed> $viewData Output of buildFilesIndexViewData.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function persistAdminGodviewStateFromViewData(Request $request, array $viewData): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $listingQuery = $viewData['listingQuery'] ?? [];
        if (!\is_array($listingQuery)) {
            return;
        }

        $adminViewScope = strtolower(trim((string) ($viewData['adminViewScope'] ?? 'owner')));
        if (!\in_array($adminViewScope, ['owner', 'all'], true)) {
            $adminViewScope = 'owner';
        }
        $canonicalViewScope = strtolower(trim((string) ($viewData['canonicalViewScope'] ?? 'me')));
        if (!\in_array($canonicalViewScope, ['me', 'user', 'all'], true)) {
            $canonicalViewScope = 'me';
        }
        if ($adminViewScope === 'all') {
            $canonicalViewScope = 'all';
        }

        $subjectUser = isset($listingQuery['subject_user']) ? (int) $listingQuery['subject_user'] : 0;
        $ownerRaw = $listingQuery['owner'] ?? null;
        $owner = \is_string($ownerRaw) || \is_int($ownerRaw) ? (int) $ownerRaw : 0;

        $currentFolder = $viewData['currentFolder'] ?? null;
        $folderPk = $currentFolder?->getId();
        $folderId = $folderPk !== null && (int) $folderPk > 0 ? (int) $folderPk : null;

        $this->adminGodviewSessionStateService->rememberState(
            $session,
            $adminViewScope,
            $canonicalViewScope,
            $subjectUser > 0 ? $subjectUser : null,
            $owner > 0 ? $owner : null,
            $folderId,
        );
    }

    /**
     * @brief JSON autocomplete for grantee pseudonym search on upload modal (max five active users, includes actor when matching).
     * @param Request $request HTTP request (query q must be at least two characters).
     * @param TranslatorInterface $translator Translator for fallback labels.
     * @return JsonResponse
     * @date 2026-05-07
     * @author Stephane H.
     */
    #[Route('/files/grantees/suggest', name: 'files_grantee_search', methods: ['GET'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    #[IsGranted('ROLE_SHARE_FRIENDS')]
    public function granteeSuggest(Request $request, TranslatorInterface $translator): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['users' => []]);
        }

        $users = $this->userRepository->searchActiveUsersForGrantSuggest((int) $user->getId(), $q, 5);
        $payload = [];
        foreach ($users as $grantee) {
            $pseudo = $grantee->getPseudonym();
            $label = $pseudo !== ''
                ? $pseudo
                : $translator->trans('files.upload.grantee_label_fallback', [], 'messages');
            $payload[] = [
                'id' => (int) $grantee->getId(),
                'label' => $label,
            ];
        }

        return new JsonResponse(['users' => $payload]);
    }

    /**
     * @brief JSON autocomplete for admin owner picker (pseudo/email), capped and restricted to active share/admin users.
     * @param Request $request HTTP request.
     * @return JsonResponse
     * @date 2026-05-07
     * @author Stephane H.
     */
    #[Route('/admin/files/owners/suggest', name: 'admin_files_owner_suggest', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminOwnerSuggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 1) {
            return new JsonResponse(['users' => []]);
        }

        $users = $this->userRepository->searchActiveUsersForAdminOwnerSuggest($q, 10);
        $payload = [];
        foreach ($users as $ownerUser) {
            $pseudo = trim((string) $ownerUser->getPseudonym());
            $email = trim((string) $ownerUser->getEmail());
            $label = $pseudo !== ''
                ? $pseudo.' ('.$email.')'
                : $email;
            $payload[] = [
                'id' => (int) $ownerUser->getId(),
                'label' => $label,
            ];
        }

        return new JsonResponse(['users' => $payload]);
    }

    /**
     * @brief Resolve admin target owner by exact pseudonym (case-insensitive); email-only input does not auto-resolve.
     * @param Request $request HTTP request (query q).
     * @return JsonResponse Payload status ok|not_found|ambiguous|invalid plus id and label when ok.
     * @date 2026-05-07
     * @author Stephane H.
     */
    #[Route('/admin/files/owners/resolve', name: 'admin_files_owner_resolve', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminOwnerResolve(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return new JsonResponse(['status' => 'invalid']);
        }

        $segment = $this->userRepository->extractPseudoSegmentForAdminOwnerResolve($q);
        if ($segment === '') {
            return new JsonResponse(['status' => 'invalid']);
        }

        $matches = $this->userRepository->findActiveUsersMatchingExactPseudo($segment, 3);
        $count = \count($matches);
        if ($count === 0) {
            return new JsonResponse(['status' => 'not_found']);
        }
        if ($count > 1) {
            return new JsonResponse(['status' => 'ambiguous']);
        }

        $ownerUser = $matches[0];
        if (!$this->userRepository->isActiveUserWithShareOrAdminRole((int) $ownerUser->getId())) {
            return new JsonResponse(['status' => 'not_found']);
        }

        $pseudo = trim((string) $ownerUser->getPseudonym());
        $email = trim((string) $ownerUser->getEmail());
        $label = $pseudo !== ''
            ? $pseudo.' ('.$email.')'
            : $email;

        return new JsonResponse([
            'status' => 'ok',
            'id' => (int) $ownerUser->getId(),
            'label' => $label,
        ]);
    }

    /**
     * @brief Build Twig variables for files_index full page and partial fragment with owned and shared-for-me sections,
     *        including shared-folder diagnostics and per-child-folder public landing sample URLs (copy link).
     * @param Request $request HTTP request.
     * @return array<string, mixed>
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function buildFilesIndexViewData(Request $request): array
    {
        /** @var User $user */
        $user = $this->getUser();
        $currentUserId = (int) $user->getId();
        $scope = $this->filesQueryScopeResolver->resolve($request, $user, $this->isGranted('ROLE_ADMIN'));
        $adminContext = $scope['adminContext'];
        $adminViewScope = $scope['viewScope'];
        $canonicalViewScope = $scope['canonicalViewScope'];
        $subjectUserIdFromScope = $scope['subjectUserId'];
        $ownedScopeUserId = $scope['ownerUserId'];
        $protoCriteria = $this->parseListingCriteriaFromQueryBag($request->query);
        $canSend = $this->isGranted('ROLE_SHARE_SEND');
        if ($this->isGranted('ROLE_SHARE') && !$canSend && !$adminContext) {
            $protoCriteria = new SharedFileOwnerListCriteria(
                $protoCriteria->searchQuery,
                $protoCriteria->sortField,
                $protoCriteria->sortDirection,
                $protoCriteria->filterPublic,
                [],
                $protoCriteria->view,
                $protoCriteria->filterHasGrant,
                [],
                $protoCriteria->uploadedAfter,
                $protoCriteria->uploadedBefore,
                $protoCriteria->updatedAfter,
                $protoCriteria->updatedBefore,
                $protoCriteria->expiresAfter,
                $protoCriteria->expiresBefore,
                'shared',
            );
        }
        $allowedExtensions = $canSend && $ownedScopeUserId !== null
            ? $this->sharedFileRepository->findDistinctExtensionsByOwner($ownedScopeUserId)
            : [];
        $sanitizedExtensions = $canSend
            ? array_values(array_intersect($protoCriteria->extensionFilters, $allowedExtensions))
            : [];
        $eligibleGranteeIds = $canSend && $ownedScopeUserId !== null
            ? $this->shareGrantRepository->findDistinctGranteeIdsForOwner($ownedScopeUserId)
            : [];
        $sanitizedGrantees = $canSend
            ? array_values(array_intersect($protoCriteria->granteeUserIds, $eligibleGranteeIds))
            : [];

        $criteria = new SharedFileOwnerListCriteria(
            $protoCriteria->searchQuery,
            $protoCriteria->sortField,
            $protoCriteria->sortDirection,
            $protoCriteria->filterPublic,
            $sanitizedExtensions,
            $protoCriteria->view,
            $protoCriteria->filterHasGrant,
            $sanitizedGrantees,
            $protoCriteria->uploadedAfter,
            $protoCriteria->uploadedBefore,
            $protoCriteria->updatedAfter,
            $protoCriteria->updatedBefore,
            $protoCriteria->expiresAfter,
            $protoCriteria->expiresBefore,
            $protoCriteria->listingScope,
        );

        $listingScope = $criteria->listingScope;
        $showOwnedListingSection = \in_array($listingScope, ['both', 'owned'], true);
        $showSharedListingSection = \in_array($listingScope, ['both', 'shared'], true);
        $paneShowShared = $showSharedListingSection;

        $capabilities = new UserFilesCapabilities(
            canManageOwned: true,
            canManageShared: true,
            canUpload: $canSend,
            canBulkActions: true,
        );

        $filesPageViewModel = null;
        /** @var array<int, \App\Dto\File\UserFilesPaneViewModel> $userFilesPanes */
        $userFilesPanes = [];

        if ($adminContext && $canonicalViewScope === 'all' && $adminViewScope === 'all') {
            $filesPageViewModel = $this->userFilesPaneBuilderService->buildAdminAllUsersPage(
                $request,
                $user,
                $criteria,
                $showOwnedListingSection,
                $paneShowShared,
                $capabilities,
            );
            $userFilesPanes = $filesPageViewModel->panes;
            $currentFolder = null;
            $folders = [];
            $breadcrumbFolders = [];
            $files = [];
            $folderShareStates = [];
            $folderSizeBytes = [];
            $folderPublicLandingUrls = [];
            $currentFolderPublicLandingUrl = null;
            $currentFolderShareState = null;
            $allSharedForMeFiles = [];
            $sharedForMeFolders = [];
            $sharedForMeCurrentFolderId = 0;
            $sharedForMeFiles = [];
            $sharedFolderSizeBytes = [];
            $grantMaps = [];
            foreach ($userFilesPanes as $p) {
                foreach ($p->grantMaps as $fid => $g) {
                    $grantMaps[$fid] = $g;
                }
            }
            $total = 0;
        } else {
            $subjectId = (int) ($subjectUserIdFromScope ?? $currentUserId);
            if ($subjectId < 1) {
                $subjectId = $currentUserId;
            }
            $subjectUserEntity = $this->userRepository->find($subjectId);
            $subjectLabel = $subjectUserEntity instanceof User
                ? (trim((string) $subjectUserEntity->getPseudonym()) !== '' ? $subjectUserEntity->getPseudonym() : $subjectUserEntity->getEmail())
                : (string) $subjectId;

            $singlePane = $this->userFilesPaneBuilderService->buildPaneViewModel(
                $subjectId,
                $subjectLabel,
                $request,
                $criteria,
                false,
                $showOwnedListingSection,
                $paneShowShared,
                $capabilities,
            );
            $userFilesPanes = [$singlePane];
            $paneFocus = trim((string) $request->query->get('pane', ''));
            $filesPageViewModel = new FilesPageViewModel(
                $canonicalViewScope,
                $listingScope,
                $userFilesPanes,
                $paneFocus !== '' ? $paneFocus : null,
                null,
                'pseudo',
                'asc',
            );

            $o = $singlePane->owned;
            $s = $singlePane->shared;
            $files = $o['files'];
            $folders = $o['folders'];
            $folderShareStates = $o['folderShareStates'];
            $folderSizeBytes = $o['folderSizeBytes'];
            $folderPublicLandingUrls = $o['folderPublicLandingUrls'];
            $currentFolderPublicLandingUrl = $o['currentFolderPublicLandingUrl'];
            $currentFolderShareState = $o['currentFolderShareState'];
            $currentFolder = $o['currentFolder'];
            $breadcrumbFolders = $o['breadcrumbFolders'];
            $sharedForMeFiles = $s['sharedForMeFiles'];
            $sharedForMeFolders = $s['sharedForMeFolders'];
            $sharedForMeFoldersById = [];
            foreach ($sharedForMeFolders as $sharedForMeFolderRow) {
                if (!\is_array($sharedForMeFolderRow)) {
                    continue;
                }
                $sharedForMeFolderId = (int) ($sharedForMeFolderRow['id'] ?? 0);
                if ($sharedForMeFolderId < 1) {
                    continue;
                }
                $sharedForMeFoldersById[$sharedForMeFolderId] = [
                    'id' => $sharedForMeFolderId,
                    'name' => (string) ($sharedForMeFolderRow['name'] ?? ''),
                ];
            }
            $sharedForMeFolders = $sharedForMeFoldersById;
            $sharedFolderSizeBytes = $s['sharedFolderSizeBytes'];
            $sharedForMeCurrentFolderId = $s['sharedForMeCurrentFolderId'];
            $grantMaps = $singlePane->grantMaps;
            $total = \count($files);
            $granteeForSharedListing = $adminContext ? $subjectId : $currentUserId;
            $allSharedForMeFiles = (!$adminContext || $paneShowShared)
                ? $this->sharedFileRepository->findSharedForGranteeAll($granteeForSharedListing, $criteria)
                : [];
            if (!$paneShowShared) {
                $sharedForMeCurrentFolderId = 0;
            }
            if ($sharedForMeCurrentFolderId > 0 && !isset($sharedForMeFolders[$sharedForMeCurrentFolderId])) {
                $sharedForMeCurrentFolderId = 0;
            }
            $sharedForMeFiles = array_values(array_filter(
                $allSharedForMeFiles,
                static function (SharedFile $sharedFile) use ($sharedForMeCurrentFolderId): bool {
                    $folderId = $sharedFile->getFolder()?->getId();
                    if ($sharedForMeCurrentFolderId > 0) {
                        return $folderId === $sharedForMeCurrentFolderId;
                    }

                    return $folderId === null;
                }
            ));
            $hasFilesInRequestedSharedFolder = false;
            $hasRequestedSharedFolderInListing = false;
            if ($sharedForMeCurrentFolderId > 0) {
                foreach ($sharedForMeFolders as $sharedForMeFolderCheck) {
                    if (!\is_array($sharedForMeFolderCheck)) {
                        continue;
                    }
                    if ((int) ($sharedForMeFolderCheck['id'] ?? 0) === $sharedForMeCurrentFolderId) {
                        $hasRequestedSharedFolderInListing = true;
                        break;
                    }
                }
                foreach ($allSharedForMeFiles as $sharedForMeFileInRequestedFolderCheck) {
                    if ((int) ($sharedForMeFileInRequestedFolderCheck->getFolder()?->getId() ?? 0) === $sharedForMeCurrentFolderId) {
                        $hasFilesInRequestedSharedFolder = true;
                        break;
                    }
                }
                if (!$hasFilesInRequestedSharedFolder && !$hasRequestedSharedFolderInListing) {
                    $sharedForMeCurrentFolderId = 0;
                    $sharedForMeFiles = array_values(array_filter(
                        $allSharedForMeFiles,
                        static fn (SharedFile $sharedFile): bool => $sharedFile->getFolder()?->getId() === null
                    ));
                }
            }
            $sharedFolderSizeBytes = [];
            foreach ($allSharedForMeFiles as $sharedForMeFile) {
                $sharedFolderId = (int) ($sharedForMeFile->getFolder()?->getId() ?? 0);
                if ($sharedFolderId < 1) {
                    continue;
                }
                $sharedFolderSizeBytes[$sharedFolderId] = (int) ($sharedFolderSizeBytes[$sharedFolderId] ?? 0) + (int) $sharedForMeFile->getByteSize();
            }
        }

        $sharedForMeFolders = isset($sharedForMeFolders) && \is_array($sharedForMeFolders) ? $sharedForMeFolders : [];
        foreach ($allSharedForMeFiles as $sharedForMeFile) {
            $sharedFolder = $sharedForMeFile->getFolder();
            $sharedFolderId = $sharedFolder?->getId();
            if ($sharedFolderId === null || $sharedFolderId <= 0) {
                continue;
            }
            $sharedForMeFolders[$sharedFolderId] = [
                'id' => $sharedFolderId,
                'name' => $sharedFolder->getName(),
            ];
        }
        ksort($sharedForMeFolders);
        if (!$paneShowShared) {
            $sharedForMeCurrentFolderId = 0;
        }
        if ($sharedForMeCurrentFolderId > 0 && !isset($sharedForMeFolders[$sharedForMeCurrentFolderId])) {
            $sharedForMeCurrentFolderId = 0;
        }
        if (!isset($sharedForMeFiles)) {
            $sharedForMeFiles = array_values(array_filter(
                $allSharedForMeFiles,
                static function (SharedFile $sharedFile) use ($sharedForMeCurrentFolderId): bool {
                    $folderId = $sharedFile->getFolder()?->getId();
                    if ($sharedForMeCurrentFolderId > 0) {
                        return $folderId === $sharedForMeCurrentFolderId;
                    }

                    return $folderId === null;
                }
            ));
        }
        if (!isset($sharedFolderSizeBytes)) {
            $sharedFolderSizeBytes = [];
            foreach ($allSharedForMeFiles as $sharedForMeFile) {
                $sharedFolderId = (int) ($sharedForMeFile->getFolder()?->getId() ?? 0);
                if ($sharedFolderId < 1) {
                    continue;
                }
                $sharedFolderSizeBytes[$sharedFolderId] = (int) ($sharedFolderSizeBytes[$sharedFolderId] ?? 0) + (int) $sharedForMeFile->getByteSize();
            }
        }
        $connection = $this->entityManager->getConnection();
        $grantRowsForUser = $adminContext ? 0 : (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM share_grant sg WHERE sg.grantee_user_id = :uid',
            ['uid' => $currentUserId]
        );
        $activeGrantedFilesNow = $adminContext ? 0 : (int) $connection->fetchOne(
            'SELECT COUNT(DISTINCT sg.shared_file_id)
             FROM share_grant sg
             INNER JOIN shared_file sf ON sf.id = sg.shared_file_id
             WHERE sg.grantee_user_id = :uid
               AND (sg.expires_at IS NULL OR sg.expires_at > NOW())
               AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
               AND sf.owner_user_id <> :uid',
            [
                'uid' => $currentUserId,
            ]
        );
        $sharedForMeSample = [];
        $sharedForMeFolderIds = [];
        foreach (array_slice($sharedForMeFiles, 0, 5) as $sharedForMeFile) {
            $folderId = $sharedForMeFile->getFolder()?->getId();
            if ($folderId !== null && $folderId > 0) {
                $sharedForMeFolderIds[$folderId] = $folderId;
            }
            $sharedForMeSample[] = [
                'id' => (int) $sharedForMeFile->getId(),
                'owner' => (int) $sharedForMeFile->getOwnerUserId(),
                'folderId' => $folderId,
            ];
        }
        if (!isset($total)) {
            $total = \count($files);
        }

        if (!isset($grantMaps)) {
            $grantMaps = [];
            $allVisibleFiles = [];
            if ($showOwnedListingSection) {
                foreach ($files as $ownedRow) {
                    $allVisibleFiles[] = $ownedRow;
                }
            }
            if ($showSharedListingSection && !$adminContext) {
                foreach ($sharedForMeFiles as $sharedRow) {
                    $allVisibleFiles[] = $sharedRow;
                }
            }
            foreach ($allVisibleFiles as $file) {
                if ($file->getId() === null) {
                    continue;
                }
                $grantMaps[$file->getId()] = $this->shareGrantRepository->findActiveGranteeIdsBySharedFile((int) $file->getId());
            }
        }
        $eligibleGrantees = [];
        foreach ($this->userRepository->findByIdsOrdered($eligibleGranteeIds) as $grantUser) {
            $eligibleGrantees[] = [
                'id' => (int) $grantUser->getId(),
                'label' => $grantUser->getPseudonym() !== '' ? $grantUser->getPseudonym() : $grantUser->getEmail(),
            ];
        }

        $granteeLabels = [];
        foreach ($this->userRepository->findByIdsOrdered($criteria->granteeUserIds) as $gu) {
            $granteeLabels[(int) $gu->getId()] = $gu->getPseudonym() !== '' ? $gu->getPseudonym() : $gu->getEmail();
        }

        $hasAdvancedFilters = $criteria->uploadedAfter !== null
            || $criteria->uploadedBefore !== null
            || $criteria->updatedAfter !== null
            || $criteria->updatedBefore !== null
            || $criteria->expiresAfter !== null
            || $criteria->expiresBefore !== null
            || $criteria->filterHasGrant !== ''
            || $criteria->granteeUserIds !== [];
        $listingChips = $this->buildListingChipDescriptors($criteria, $granteeLabels);
        $ownerLabelsByFileId = [];
        $ownerLabelsByFolderId = [];
        $adminAllShareUsersCount = 0;
        if ($filesPageViewModel instanceof FilesPageViewModel && $filesPageViewModel->usersPagination !== null) {
            $adminAllShareUsersCount = $filesPageViewModel->usersPagination->totalUsers;
        }
        if ($adminContext && !($canonicalViewScope === 'all' && $adminViewScope === 'all')) {
            $ownerIds = [];
            foreach ($files as $ownedFile) {
                $ownerIds[(int) $ownedFile->getOwnerUserId()] = (int) $ownedFile->getOwnerUserId();
            }
            $ownerLabels = [];
            foreach ($this->userRepository->findByIdsOrdered(array_values($ownerIds)) as $ownerUser) {
                $ownerLabels[(int) $ownerUser->getId()] = $ownerUser->getPseudonym() !== ''
                    ? $ownerUser->getPseudonym()
                    : $ownerUser->getEmail();
            }
            foreach ($files as $ownedFile) {
                $fileId = (int) ($ownedFile->getId() ?? 0);
                if ($fileId < 1) {
                    continue;
                }
                $ownerId = (int) $ownedFile->getOwnerUserId();
                $ownerLabelsByFileId[$fileId] = $ownerLabels[$ownerId] ?? (string) $ownerId;
            }
        }
        if ($showSharedListingSection && !($adminContext && $canonicalViewScope === 'all' && $adminViewScope === 'all')) {
            $sharedOwnerIds = [];
            $sharedOwnerByFolderId = [];
            foreach ($sharedForMeFiles as $sharedForMeFile) {
                $sharedOwnerId = (int) $sharedForMeFile->getOwnerUserId();
                if ($sharedOwnerId > 0) {
                    $sharedOwnerIds[$sharedOwnerId] = $sharedOwnerId;
                }
            }
            foreach ($allSharedForMeFiles as $sharedForMeFile) {
                $sharedOwnerId = (int) $sharedForMeFile->getOwnerUserId();
                if ($sharedOwnerId > 0) {
                    $sharedOwnerIds[$sharedOwnerId] = $sharedOwnerId;
                }
                $sharedFolderId = (int) ($sharedForMeFile->getFolder()?->getId() ?? 0);
                if ($sharedFolderId > 0 && $sharedOwnerId > 0) {
                    $currentFolderOwnerId = (int) ($sharedOwnerByFolderId[$sharedFolderId] ?? 0);
                    if ($currentFolderOwnerId < 1 || $sharedOwnerId < $currentFolderOwnerId) {
                        $sharedOwnerByFolderId[$sharedFolderId] = $sharedOwnerId;
                    }
                }
            }
            $sharedOwnerLabels = [];
            foreach ($this->userRepository->findByIdsOrdered(array_values($sharedOwnerIds)) as $sharedOwnerUser) {
                $sharedOwnerLabel = trim((string) $sharedOwnerUser->getPseudonym());
                if ($sharedOwnerLabel === '') {
                    $sharedOwnerLabel = trim((string) $sharedOwnerUser->getEmail());
                }
                $sharedOwnerLabels[(int) $sharedOwnerUser->getId()] = $sharedOwnerLabel;
            }
            foreach ($sharedForMeFiles as $sharedForMeFile) {
                $sharedFileId = (int) ($sharedForMeFile->getId() ?? 0);
                if ($sharedFileId < 1) {
                    continue;
                }
                $sharedOwnerId = (int) $sharedForMeFile->getOwnerUserId();
                $ownerLabelsByFileId[$sharedFileId] = $sharedOwnerLabels[$sharedOwnerId] ?? (string) $sharedOwnerId;
            }
            foreach ($sharedForMeFolders as $sharedForMeFolder) {
                if (!\is_array($sharedForMeFolder)) {
                    continue;
                }
                $sharedFolderId = (int) ($sharedForMeFolder['id'] ?? 0);
                if ($sharedFolderId < 1) {
                    continue;
                }
                $sharedFolderOwnerId = (int) ($sharedOwnerByFolderId[$sharedFolderId] ?? 0);
                if ($sharedFolderOwnerId < 1) {
                    continue;
                }
                $ownerLabelsByFolderId[$sharedFolderId] = $sharedOwnerLabels[$sharedFolderOwnerId] ?? (string) $sharedFolderOwnerId;
            }
        }
        if ($showSharedListingSection && $criteria->sortField === 'pseudo' && !$criteria->isSortNeutral()) {
            $sharedForMeFolders = $this->sortSharedFoldersByOwnerLabel($sharedForMeFolders, $ownerLabelsByFolderId, $criteria->sortDirection);
        }

        $listingQueryMerged = $criteria->toQueryParams();
        if ($currentFolder !== null && $showOwnedListingSection) {
            $folderPk = $currentFolder->getId();
            if ($folderPk !== null && (int) $folderPk > 0) {
                $listingQueryMerged['folder'] = (int) $folderPk;
            }
        }
        if ($adminContext) {
            $listingQueryMerged['admin_context'] = '1';
            $listingQueryMerged['admin_view_scope'] = $adminViewScope;
            if ($adminViewScope === 'owner') {
                $ownerIdForQuery = $scope['ownerFilter'] !== ''
                    ? $scope['ownerFilter']
                    : ($ownedScopeUserId !== null ? (string) (int) $ownedScopeUserId : '');
                if ($ownerIdForQuery !== '') {
                    $listingQueryMerged['owner'] = $ownerIdForQuery;
                }
            }
            if ($scope['ownerQuery'] !== '') {
                $listingQueryMerged['owner_query'] = $scope['ownerQuery'];
            }
            if ($adminViewScope === 'all') {
                unset($listingQueryMerged['owner'], $listingQueryMerged['owner_query'], $listingQueryMerged['subject_user']);
                $listingQueryMerged['view_scope'] = 'all';
            }
        }
        $listingQueryMerged['view_scope'] = $canonicalViewScope;
        if ($canonicalViewScope === 'user' && $subjectUserIdFromScope !== null && (int) $subjectUserIdFromScope > 0) {
            $listingQueryMerged['subject_user'] = (int) $subjectUserIdFromScope;
        }
        if ($filesPageViewModel instanceof FilesPageViewModel && $filesPageViewModel->usersPagination !== null) {
            $up = $filesPageViewModel->usersPagination;
            $listingQueryMerged['users_page'] = $up->page;
            $listingQueryMerged['users_page_size'] = $up->pageSize;
            $listingQueryMerged['users_sort'] = $up->sortField;
            $listingQueryMerged['users_dir'] = $up->sortDirection;
        }
        $paneQuery = trim((string) $request->query->get('pane', ''));
        if ($paneQuery !== '') {
            $listingQueryMerged['pane'] = $paneQuery;
        }

        foreach ($request->query->all() as $ufOrSfKey => $ufOrSfValue) {
            if (!\is_string($ufOrSfKey) || \is_array($ufOrSfValue)) {
                continue;
            }
            if (1 !== preg_match('/^(uf|sf)_[1-9]\\d*$/', $ufOrSfKey)) {
                continue;
            }
            $ufOrSfInt = (int) $ufOrSfValue;
            if ($ufOrSfInt > 0) {
                $listingQueryMerged[$ufOrSfKey] = $ufOrSfInt;
            }
        }

        $useUserFilesPanes = $adminContext && $canonicalViewScope === 'all' && $adminViewScope === 'all';
        $showLegacyOwnedSection = $useUserFilesPanes ? false : $showOwnedListingSection;
        $showLegacySharedSection = $useUserFilesPanes ? false : $showSharedListingSection;
        if ($sharedForMeCurrentFolderId > 0 && $paneShowShared) {
            $listingQueryMerged['shared_folder'] = $sharedForMeCurrentFolderId;
        }

        $adminScopeBannerLabel = $this->resolveAdminScopeBannerLabel(
            $request,
            $adminContext,
            $adminViewScope,
            $canonicalViewScope,
            $subjectUserIdFromScope,
            $currentUserId
        );
        $adminOwnerFallbackNotice = '';
        if ($adminContext && $scope['ownerFallbackApplied']) {
            $fallbackUserEntity = $this->userRepository->find($currentUserId);
            $fallbackLabel = $this->formatUserDisplayLabelForAdminBanner($fallbackUserEntity, $currentUserId);
            $adminOwnerFallbackNotice = $this->translator->trans('files.admin.owner_fallback_notice', [
                '%id%' => (string) $currentUserId,
                '%label%' => $fallbackLabel,
            ], 'messages', $request->getLocale());
        }

        return [
            'files' => $files,
            'folders' => $folders,
            'folderShareStates' => $folderShareStates,
            'folderSizeBytes' => $folderSizeBytes,
            'folderPublicLandingUrls' => $folderPublicLandingUrls,
            'currentFolderPublicLandingUrl' => $currentFolderPublicLandingUrl,
            'currentFolderShareState' => $currentFolderShareState,
            'currentFolder' => $currentFolder,
            'isCurrentFolderCompletelyEmpty' => $folders === [] && $files === [],
            'breadcrumbFolders' => $breadcrumbFolders,
            'sharedForMeFiles' => $useUserFilesPanes && $adminContext ? [] : $sharedForMeFiles,
            'sharedForMeFolders' => $useUserFilesPanes && $adminContext ? [] : array_values($sharedForMeFolders),
            'sharedFolderSizeBytes' => $sharedFolderSizeBytes,
            'sharedForMeCurrentFolderId' => $sharedForMeCurrentFolderId,
            'hasSharedForMe' => $allSharedForMeFiles !== [] || $sharedForMeFolders !== [],
            'total' => $total,
            'grantMaps' => $grantMaps,
            'listingCriteria' => $criteria,
            'listingQuery' => $listingQueryMerged,
            'listingRetain' => [
                'q' => $criteria->searchQuery,
                'sort' => $criteria->sortField,
                'dir' => $criteria->sortDirection,
                'filter_public' => $criteria->filterPublic,
                'view' => $criteria->view,
                'extensions' => $criteria->extensionFilters,
                'filter_has_grant' => $criteria->filterHasGrant,
                'grantees' => $criteria->granteeUserIds,
                'uploaded_after' => $criteria->uploadedAfter,
                'uploaded_before' => $criteria->uploadedBefore,
                'updated_after' => $criteria->updatedAfter,
                'updated_before' => $criteria->updatedBefore,
                'expires_after' => $criteria->expiresAfter,
                'expires_before' => $criteria->expiresBefore,
                'folder' => $showOwnedListingSection ? $currentFolder?->getId() : null,
                'shared_folder' => ($sharedForMeCurrentFolderId > 0 && $paneShowShared) ? $sharedForMeCurrentFolderId : null,
                'listing_scope' => $criteria->listingScope,
                'admin_context' => $adminContext ? '1' : '0',
                'admin_view_scope' => $adminContext ? $adminViewScope : '',
                'owner' => ($adminContext && $adminViewScope === 'owner') ? $scope['ownerFilter'] : '',
                'owner_query' => $adminContext ? $scope['ownerQuery'] : '',
                'view_scope' => $canonicalViewScope,
                'subject_user' => ($canonicalViewScope === 'user' && $subjectUserIdFromScope !== null && (int) $subjectUserIdFromScope > 0)
                    ? (string) (int) $subjectUserIdFromScope
                    : '',
                'users_page' => $filesPageViewModel instanceof FilesPageViewModel && $filesPageViewModel->usersPagination !== null
                    ? (string) $filesPageViewModel->usersPagination->page
                    : '',
                'users_page_size' => $filesPageViewModel instanceof FilesPageViewModel && $filesPageViewModel->usersPagination !== null
                    ? (string) $filesPageViewModel->usersPagination->pageSize
                    : '',
                'users_sort' => $filesPageViewModel instanceof FilesPageViewModel && $filesPageViewModel->usersPagination !== null
                    ? $filesPageViewModel->usersPagination->sortField
                    : '',
                'users_dir' => $filesPageViewModel instanceof FilesPageViewModel && $filesPageViewModel->usersPagination !== null
                    ? $filesPageViewModel->usersPagination->sortDirection
                    : '',
                'pane' => $paneQuery,
            ],
            'listingChips' => $listingChips,
            'listingQueryResetAdvanced' => $this->listingQueryWithoutChip($criteria, 'advanced_all'),
            'hasAdvancedFilters' => $hasAdvancedFilters,
            'allowedExtensions' => $allowedExtensions,
            'eligibleGrantees' => $eligibleGrantees,
            'sortField' => $criteria->sortField,
            'sortDirection' => $criteria->sortDirection,
            'layoutView' => $criteria->view,
            'searchQueryValue' => $criteria->searchQuery,
            'filterPublicValue' => $criteria->filterPublic,
            'filterHasGrantValue' => $criteria->filterHasGrant,
            'listingScope' => $criteria->listingScope,
            'showOwnedListingSection' => $showLegacyOwnedSection,
            'showSharedListingSection' => $showLegacySharedSection,
            'paneShowShared' => $paneShowShared,
            'useUserFilesPanes' => $useUserFilesPanes,
            'requireAdminTargetOwner' => $useUserFilesPanes,
            'userFilesPanes' => $userFilesPanes,
            'filesPageViewModel' => $filesPageViewModel,
            'canonicalViewScope' => $canonicalViewScope,
            'usersPagination' => $filesPageViewModel instanceof FilesPageViewModel ? $filesPageViewModel->usersPagination : null,
            'adminContext' => $adminContext,
            'adminViewScope' => $adminViewScope,
            'adminOwnerFilterValue' => $scope['ownerFilter'],
            'adminOwnerQueryValue' => $scope['ownerQuery'],
            'adminOwnerFallbackApplied' => (bool) $scope['ownerFallbackApplied'],
            'adminScopeBannerLabel' => $adminScopeBannerLabel,
            'adminOwnerFallbackNotice' => $adminOwnerFallbackNotice,
            'ownerLabelsByFileId' => $ownerLabelsByFileId,
            'ownerLabelsByFolderId' => $ownerLabelsByFolderId,
            'adminAllShareUsersCount' => $adminAllShareUsersCount,
            'filesIndexRoute' => $adminContext ? 'admin_files_index' : 'files_index',
            'currentOwnedScopeUserId' => $ownedScopeUserId,
            'currentLocale' => $request->getLocale(),
            'csrfUpload' => self::CSRF_UPLOAD,
            'csrfDelete' => self::CSRF_DELETE,
            'csrfRename' => self::CSRF_RENAME,
            'csrfVisibility' => self::CSRF_VISIBILITY,
            'csrfGrant' => self::CSRF_GRANT,
            'csrfRevoke' => self::CSRF_REVOKE,
            'csrfSharePublic' => self::CSRF_SHARE_PUBLIC,
            'csrfShareFriends' => self::CSRF_SHARE_FRIENDS,
            'csrfShareBulkPublic' => self::CSRF_SHARE_BULK_PUBLIC,
            'csrfShareBulkFriends' => self::CSRF_SHARE_BULK_FRIENDS,
            'csrfFolderCreate' => self::CSRF_FOLDER_CREATE,
            'csrfFolderDelete' => self::CSRF_FOLDER_DELETE,
            'csrfFolderSharePublic' => self::CSRF_FOLDER_SHARE_PUBLIC,
            'csrfFolderShareFriends' => self::CSRF_FOLDER_SHARE_FRIENDS,
            'csrfFolderRename' => self::CSRF_FOLDER_RENAME,
            'csrfExtract' => self::CSRF_EXTRACT,
            'maxUploadBytes' => $this->resolveAppMaxUploadBytes(),
        ];
    }

    /**
     * @brief Human-readable label for the admin scope badge segment.
     * @param Request $request Current request (locale).
     * @param bool $adminContext Whether admin godview is active.
     * @param string $adminViewScope Raw admin_view_scope query value (owner|all).
     * @param string $canonicalViewScope Resolved canonical view scope.
     * @param int|null $subjectUserIdFromScope Effective subject user id from scope resolver.
     * @param int $currentUserId Authenticated user id.
     * @return string Label for scope badge or empty when not in admin context.
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function resolveAdminScopeBannerLabel(
        Request $request,
        bool $adminContext,
        string $adminViewScope,
        string $canonicalViewScope,
        ?int $subjectUserIdFromScope,
        int $currentUserId,
    ): string {
        if (!$adminContext) {
            return '';
        }
        $locale = $request->getLocale();
        if ($adminViewScope === 'all' && $canonicalViewScope === 'all') {
            return $this->translator->trans('files.admin.view_scope_all', [], 'messages', $locale);
        }
        $sid = $subjectUserIdFromScope !== null && (int) $subjectUserIdFromScope > 0
            ? (int) $subjectUserIdFromScope
            : $currentUserId;

        return $this->formatUserDisplayLabelForAdminBanner($this->userRepository->find($sid), $sid);
    }

    /**
     * @brief Format pseudonym-only label for admin scope UI strings with numeric id fallback.
     * @param User|null $userEntity Loaded user or null.
     * @param int $fallbackId Fallback id when entity missing.
     * @return string Display label.
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function formatUserDisplayLabelForAdminBanner(?User $userEntity, int $fallbackId): string
    {
        if ($userEntity instanceof User) {
            $pseudo = trim((string) $userEntity->getPseudonym());
            if ($pseudo !== '') {
                return $pseudo;
            }
        }

        return (string) $fallbackId;
    }

    /**
     * @brief Resolve an absolute public landing URL for one owned folder when folder-level public sharing is effectively active.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder for the subtree query.
     * @return string|null Absolute URL (scheme and host) or null when public sharing is inactive or token cannot be materialized.
     * @date 2026-05-05
     * @author Stephane H.
     */
    private function resolvePublicLandingUrlForOwnerFolderSubtree(int $ownerUserId, Folder $folder): ?string
    {
        if ((int) $folder->getOwnerUserId() !== $ownerUserId) {
            return null;
        }
        if (!$folder->isPublicShareEffectivelyActive()) {
            return null;
        }

        $this->folderPublicTokenService->ensurePublicFolderToken($folder);
        $token = $folder->getPublicFolderToken();
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $this->generateUrl('folder_public_landing', ['publicToken' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @brief Compute recursive byte size for one owned folder.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder.
     * @return int Total bytes for files in folder subtree.
     * @date 2026-04-30
     * @author Stephane H.
     */
    private function computeOwnedFolderSizeBytes(int $ownerUserId, Folder $folder): int
    {
        $totalBytes = 0;
        $subtreeFolders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        foreach ($subtreeFolders as $subFolder) {
            $rows = $this->sharedFileRepository->findBy([
                'ownerUserId' => $ownerUserId,
                'folder' => $subFolder,
            ]);
            foreach ($rows as $row) {
                if ($row instanceof SharedFile) {
                    $totalBytes += (int) $row->getByteSize();
                }
            }
        }

        return $totalBytes;
    }

    /**
     * @brief Detect POST retain bag aligned with admin godview all-users scope (proxy uploads and folders).
     * @param Request $request HTTP request.
     * @return bool
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function isAdminGodviewAllTargetOwnerContext(Request $request): bool
    {
        $bag = $request->request;
        if ((string) $bag->get('_retain_admin_context', '') !== '1') {
            return false;
        }
        if (strtolower(trim((string) $bag->get('_retain_admin_view_scope', ''))) !== 'all') {
            return false;
        }
        $vs = strtolower(trim((string) $bag->get('_retain_view_scope', '')));

        return $vs === 'all';
    }

    /**
     * @brief Resolve effective file owner for upload and folder create (admin proxy vs actor).
     * @param Request $request HTTP request.
     * @param User $actor Authenticated user.
     * @return array{ownerId: int}|array{error: string}
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function resolveUploadOrFolderTargetOwnerId(Request $request, User $actor): array
    {
        $actorId = (int) $actor->getId();
        $raw = $request->request->get('target_owner_user_id');
        $hasParam = $raw !== null && trim((string) $raw) !== '';
        $godviewAll = $this->isAdminGodviewAllTargetOwnerContext($request);

        if (!$hasParam) {
            if ($godviewAll && $this->isGranted('ROLE_ADMIN')) {
                return ['error' => 'files.flash.target_owner_required'];
            }

            return ['ownerId' => $actorId];
        }

        if (!$this->isGranted('ROLE_ADMIN')) {
            return ['error' => 'files.flash.target_owner_forbidden'];
        }
        if (!$godviewAll) {
            return ['error' => 'files.flash.target_owner_context_invalid'];
        }
        $tid = (int) $raw;
        if ($tid < 1 || !$this->userRepository->isActiveUserWithShareOrAdminRole($tid)) {
            return ['error' => 'files.flash.target_owner_invalid'];
        }

        return ['ownerId' => $tid];
    }

    /**
     * @brief Resolve upload target folder id with explicit target_folder_id priority and owner validation.
     * @param Request $request HTTP request.
     * @param int $ownerId Effective owner id.
     * @return array{folderId: int}|array{error: string}
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function resolveUploadTargetFolderId(Request $request, int $ownerId): array
    {
        $targetRaw = trim((string) $request->request->get('target_folder_id', ''));
        if ($targetRaw !== '') {
            $targetId = (int) $targetRaw;
            if ($targetId < 1) {
                return ['error' => 'files.flash.target_folder_invalid'];
            }
            if (!$this->folderTreeService->resolveCurrentFolder($ownerId, $targetId) instanceof Folder) {
                return ['error' => 'files.flash.target_folder_invalid'];
            }

            return ['folderId' => $targetId];
        }

        return ['folderId' => (int) $request->request->get('_retain_folder', $request->request->get('folder', 0))];
    }

    /**
     * @brief Accept encrypted upload for the current owner with private non-shared defaults.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator for JSON error responses when `expectsJson` is true.
     * @return Response
     * @date 2026-05-08
     * @author Stephane H.
     */
    #[Route('/files/upload', name: 'files_upload', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function upload(Request $request, TranslatorInterface $translator): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_UPLOAD, (string) $request->request->get('_csrf_token', '')))) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.csrf_invalid', 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, $resolved['error']);
        }
        $ownerId = $resolved['ownerId'];

        /** @var UploadedFile|null $uploaded */
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            $uploadErrorCode = $uploaded instanceof UploadedFile ? (int) $uploaded->getError() : null;
            if (\in_array($uploadErrorCode, [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.upload_too_large');
            }

            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.upload_invalid');
        }
        $maxUploadBytes = $this->resolveSingleRequestMaxUploadBytes();
        if ($uploaded->getSize() > $maxUploadBytes) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.upload_too_large');
        }

        try {
            $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerId, (int) $uploaded->getSize());
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.quota_exceeded', 413);
            }

            throw $e;
        }

        $displayName = trim($uploaded->getClientOriginalName());
        if ($displayName === '') {
            $displayName = 'file';
        }
        $resolvedFolder = $this->resolveUploadTargetFolderId($request, $ownerId);
        if (isset($resolvedFolder['error'])) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, $resolvedFolder['error'], 400);
        }
        $targetFolderId = $resolvedFolder['folderId'];
        $targetFolder = $this->folderTreeService->resolveCurrentFolder($ownerId, $targetFolderId > 0 ? $targetFolderId : null);
        $normalizedDisplay = Folder::normalizeName($displayName);
        if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerId, $targetFolder, $normalizedDisplay, null) instanceof SharedFile) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.upload_name_conflict');
        }
        if ($this->siblingFolderExistsWithNormalizedName($ownerId, $targetFolder, $normalizedDisplay, null)) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.upload_name_conflict');
        }

        $plainPath = $uploaded->getRealPath();
        if (!is_string($plainPath) || $plainPath === '' || !is_readable($plainPath)) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.upload_invalid');
        }

        $relativeDir = \sprintf('var/shared/%d', $ownerId);
        $absoluteDir = $this->projectDir.'/'.$relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.storage_failed');
        }

        $storageName = bin2hex(random_bytes(16)).'.dat';
        $absolutePath = $absoluteDir.'/'.$storageName;

        try {
            $plainLen = $this->fileEncryptionService->encryptPlainFileToV2Storage($plainPath, $absolutePath);
        } catch (\RuntimeException) {
            return $this->uploadJsonErrorOrRedirect($request, $translator, 'files.flash.storage_failed');
        }

        $sharedFile = new SharedFile(
            $ownerId,
            $absolutePath,
            'private',
            bin2hex(random_bytes(16)),
            $displayName,
            $plainLen,
            null,
            null
        );
        $sharedFile->setFolder($targetFolder);

        $this->entityManager->persist($sharedFile);
        $this->entityManager->flush();
        if ($targetFolder instanceof Folder) {
            $this->applyFolderPoliciesToUploadedFile($sharedFile, $targetFolder, $ownerId);
        }

        return $this->uploadSuccessJsonOrRedirect($request, $translator);
    }

    /**
     * @brief Start a chunked upload session (large files).
     * @param Request $request HTTP request (expected_bytes, original_name, listing retain fields).
     * @param TranslatorInterface $translator Translator for JSON errors.
     * @return JsonResponse|Response
     * @date 2026-05-08
     * @author Stephane H.
     */
    #[Route('/files/upload/session', name: 'files_upload_session', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function uploadSession(Request $request, TranslatorInterface $translator): JsonResponse|Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_UPLOAD, (string) $request->request->get('_csrf_token', '')))) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.csrf_invalid', 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, $resolved['error'], 400);
        }
        $ownerId = $resolved['ownerId'];
        $expectedBytes = (int) $request->request->get('expected_bytes', 0);
        $originalName = trim((string) $request->request->get('original_name', ''));
        if ($originalName === '') {
            $originalName = 'file';
        }
        $resolvedFolder = $this->resolveUploadTargetFolderId($request, $ownerId);
        if (isset($resolvedFolder['error'])) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, $resolvedFolder['error'], 400);
        }
        $folderRetain = $resolvedFolder['folderId'];

        $maxUploadBytes = $this->resolveAppMaxUploadBytes();
        if ($expectedBytes < 0 || $expectedBytes > $maxUploadBytes) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.upload_too_large', 400);
        }

        try {
            $payload = $this->chunkedUploadService->createSession($ownerId, $expectedBytes, $originalName, $folderRetain);
        } catch (\RuntimeException $e) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, $this->mapChunkExceptionToFlashKey($e), 400);
        }

        return new JsonResponse(['status' => 'ok'] + $payload);
    }

    /**
     * @brief Append one ordered chunk to an upload session.
     * @param Request $request HTTP request (upload_id, chunk_index, chunk file).
     * @param TranslatorInterface $translator Translator for JSON errors.
     * @return JsonResponse|Response
     * @date 2026-05-03
     * @author Stephane H.
     */
    #[Route('/files/upload/chunk', name: 'files_upload_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function uploadChunk(Request $request, TranslatorInterface $translator): JsonResponse|Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_UPLOAD, (string) $request->request->get('_csrf_token', '')))) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.csrf_invalid', 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, $resolved['error'], 400);
        }
        $ownerId = $resolved['ownerId'];
        $uploadId = trim((string) $request->request->get('upload_id', ''));
        $chunkIndex = (int) $request->request->get('chunk_index', -1);
        /** @var UploadedFile|null $chunk */
        $chunk = $request->files->get('chunk');
        if ($uploadId === '' || $chunkIndex < 0 || !$chunk instanceof UploadedFile) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.upload_invalid', 400);
        }
        if (!$chunk->isValid()) {
            if (\in_array((int) $chunk->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.upload_chunk_too_large', 413);
            }

            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.upload_invalid', 400);
        }
        $maxChunkBytes = $this->resolveMaxChunkRequestBytes();
        if ((int) $chunk->getSize() > $maxChunkBytes) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.upload_chunk_too_large', 413);
        }

        try {
            $progress = $this->chunkedUploadService->appendChunk($uploadId, $ownerId, $chunkIndex, $chunk);
        } catch (\RuntimeException $e) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, $this->mapChunkExceptionToFlashKey($e), 400);
        }

        return new JsonResponse(['status' => 'ok'] + $progress);
    }

    /**
     * @brief Finalize chunked upload: encrypt to storage and create SharedFile row.
     * @param Request $request HTTP request (upload_id).
     * @param TranslatorInterface $translator Translator for JSON errors.
     * @return JsonResponse|Response
     * @date 2026-05-03
     * @author Stephane H.
     */
    #[Route('/files/upload/finalize', name: 'files_upload_finalize', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function uploadFinalize(Request $request, TranslatorInterface $translator): JsonResponse|Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_UPLOAD, (string) $request->request->get('_csrf_token', '')))) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.csrf_invalid', 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, $resolved['error'], 400);
        }
        $ownerId = $resolved['ownerId'];
        $uploadId = trim((string) $request->request->get('upload_id', ''));
        if ($uploadId === '') {
            return $this->chunkUploadJsonOrRedirect($request, $translator, 'files.flash.upload_invalid', 400);
        }

        $maxUploadBytes = $this->resolveAppMaxUploadBytes();

        try {
            $sharedFile = $this->chunkedUploadService->finalizeAndPersist($ownerId, $uploadId, $maxUploadBytes);
        } catch (\RuntimeException $e) {
            return $this->chunkUploadJsonOrRedirect($request, $translator, $this->mapChunkExceptionToFlashKey($e), 400);
        }

        $folder = $sharedFile->getFolder();
        if ($folder instanceof Folder) {
            $this->applyFolderPoliciesToUploadedFile($sharedFile, $folder, $ownerId);
        }

        return $this->uploadSuccessJsonOrRedirect($request, $translator);
    }

    /**
     * @brief Preflight ZIP extraction limits and archive metadata for the modal.
     * @param int $id Shared file identifier.
     * @return JsonResponse
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/files/{id}/extract/preflight', name: 'files_extract_zip_preflight', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function extractZipPreflight(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sharedFile = $this->sharedFileRepository->find($id);
        if (!$sharedFile instanceof SharedFile) {
            return new JsonResponse(['status' => 'error', 'message_key' => 'files.flash.not_found'], 404);
        }
        if (!$this->canActorMutateOwnedSharedFile($user, $sharedFile)) {
            return new JsonResponse(['status' => 'error', 'message_key' => 'files.flash.not_owner'], 403);
        }

        $limits = $this->zipExtractLimitsResolver->resolveForActor($this->isGranted('ROLE_ADMIN'));
        try {
            $preflight = $this->zipExtractService->buildPreflight((int) $sharedFile->getOwnerUserId(), $sharedFile, $limits);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message_key' => ZipExtractService::mapExceptionToFlashKey($e),
            ], 400);
        }

        return new JsonResponse([
            'status' => 'ok',
            'zip_file_name' => $preflight['zip_file_name'],
            'zip_file_bytes' => $preflight['zip_file_bytes'],
            'zip_file_bytes_formatted' => $this->binaryByteFormatter->format((int) $preflight['zip_file_bytes']),
            'max_uncompressed_bytes' => $preflight['max_uncompressed_bytes'],
            'max_uncompressed_bytes_formatted' => $this->binaryByteFormatter->format((int) $preflight['max_uncompressed_bytes']),
            'max_file_count' => $preflight['max_file_count'],
            'max_job_seconds' => $preflight['max_job_seconds'],
            'limits_tier' => $preflight['limits_tier'],
        ]);
    }

    /**
     * @brief Start a ZIP extraction job for one owned archive file.
     * @param Request $request HTTP request (mode, conflict_policy, delete_zip).
     * @param TranslatorInterface $translator Translator for JSON errors.
     * @param int $id Shared file identifier.
     * @return JsonResponse|Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/files/{id}/extract', name: 'files_extract_zip', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function extractZipStart(Request $request, TranslatorInterface $translator, int $id): JsonResponse|Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_EXTRACT, (string) $request->request->get('_csrf_token', '')))) {
            return $this->extractJsonOrRedirect($request, $translator, 'files.flash.csrf_invalid', 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            return $this->extractJsonOrRedirect($request, $translator, $resolved['error'], 400);
        }
        $ownerId = $resolved['ownerId'];

        $sharedFile = $this->sharedFileRepository->find($id);
        if (!$sharedFile instanceof SharedFile) {
            return $this->extractJsonOrRedirect($request, $translator, 'files.flash.not_found', 404);
        }
        if (!$this->canActorMutateOwnedSharedFile($user, $sharedFile)) {
            return $this->extractJsonOrRedirect($request, $translator, 'files.flash.not_owner', 403);
        }

        $mode = trim((string) $request->request->get('mode', ZipExtractService::MODE_HERE));
        $conflictPolicy = trim((string) $request->request->get('conflict_policy', ZipExtractService::CONFLICT_ABORT));
        $deleteZip = filter_var($request->request->get('delete_zip', false), FILTER_VALIDATE_BOOL);
        $limits = $this->zipExtractLimitsResolver->resolveForActor($this->isGranted('ROLE_ADMIN'));

        try {
            $result = $this->zipExtractService->createJob($ownerId, $sharedFile, $mode, $conflictPolicy, $deleteZip, $limits);
        } catch (\RuntimeException $e) {
            return $this->extractJsonOrRedirect($request, $translator, ZipExtractService::mapExceptionToFlashKey($e), 400);
        }

        return new JsonResponse([
            'status' => 'ok',
            'job_id' => $result['job_id'],
            'total_entries' => $result['total_entries'],
            'total_bytes' => $result['total_bytes'],
            'phase' => $result['phase'],
        ]);
    }

    /**
     * @brief Process the next extraction batch and return progress JSON.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator for JSON errors.
     * @param string $jobId Extraction job identifier.
     * @return JsonResponse|Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/files/extract/{jobId}/tick', name: 'files_extract_zip_tick', methods: ['POST'], requirements: ['jobId' => '[a-f0-9]{32}'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function extractZipTick(Request $request, TranslatorInterface $translator, string $jobId): JsonResponse|Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_EXTRACT, (string) $request->request->get('_csrf_token', '')))) {
            return $this->extractJsonOrRedirect($request, $translator, 'files.flash.csrf_invalid', 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            return $this->extractJsonOrRedirect($request, $translator, $resolved['error'], 400);
        }
        $ownerId = $resolved['ownerId'];

        try {
            $progress = $this->zipExtractService->tickJob($ownerId, $jobId);
        } catch (\RuntimeException $e) {
            return $this->extractJsonOrRedirect($request, $translator, ZipExtractService::mapExceptionToFlashKey($e), 400);
        }

        $payload = ['status' => 'ok'] + $progress;
        if (($progress['done'] ?? false) && ($progress['phase'] ?? '') === 'done') {
            $payload['message'] = $translator->trans('files.flash.extract_done', [], 'messages');
        }
        if (($progress['done'] ?? false) && ($progress['phase'] ?? '') === 'failed') {
            $errorKey = (string) ($progress['error_message'] ?? 'zip_extract.invalid');
            $payload['status'] = 'error';
            $payload['message'] = $translator->trans(
                ZipExtractService::mapExceptionToFlashKey(new \RuntimeException($errorKey)),
                [],
                'messages'
            );
        }

        return new JsonResponse($payload);
    }

    /**
     * @brief Cancel an in-progress extraction job.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator for JSON errors.
     * @param string $jobId Extraction job identifier.
     * @return JsonResponse|Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/files/extract/{jobId}/cancel', name: 'files_extract_zip_cancel', methods: ['POST'], requirements: ['jobId' => '[a-f0-9]{32}'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function extractZipCancel(Request $request, TranslatorInterface $translator, string $jobId): JsonResponse|Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_EXTRACT, (string) $request->request->get('_csrf_token', '')))) {
            return $this->extractJsonOrRedirect($request, $translator, 'files.flash.csrf_invalid', 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            return $this->extractJsonOrRedirect($request, $translator, $resolved['error'], 400);
        }
        $ownerId = $resolved['ownerId'];

        try {
            $progress = $this->zipExtractService->cancelJob($ownerId, $jobId);
        } catch (\RuntimeException $e) {
            return $this->extractJsonOrRedirect($request, $translator, ZipExtractService::mapExceptionToFlashKey($e), 400);
        }

        return new JsonResponse([
            'status' => 'ok',
            'message' => $translator->trans('files.flash.extract_cancelled', [], 'messages'),
        ] + $progress);
    }

    /**
     * @brief JSON error or flash+redirect for ZIP extraction endpoints.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator.
     * @param string $messageKey Flash message key.
     * @param int $httpStatus HTTP status for JSON clients.
     * @return JsonResponse|Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function extractJsonOrRedirect(Request $request, TranslatorInterface $translator, string $messageKey, int $httpStatus): JsonResponse|Response
    {
        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $translator->trans($messageKey, [], 'messages'),
            ], $httpStatus);
        }
        $this->addFlash('danger', $messageKey);

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Whether the authenticated user may mutate one owned shared file (owner or admin).
     * @param User $user Authenticated user.
     * @param SharedFile $sharedFile Target file.
     * @return bool
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function canActorMutateOwnedSharedFile(User $user, SharedFile $sharedFile): bool
    {
        if ($sharedFile->getOwnerUserId() === (int) $user->getId()) {
            return true;
        }

        return $this->isGranted('ROLE_ADMIN');
    }

    /**
     * @brief Map chunk upload service exceptions to flash translation keys.
     * @param \RuntimeException $exception Thrown service exception.
     * @return string Message key in messages domain.
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function mapChunkExceptionToFlashKey(\RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'chunk_upload.session_not_found' => 'files.flash.upload_invalid',
            'chunk_upload.chunk_invalid' => 'files.flash.upload_invalid',
            'chunk_upload.chunk_read_failed' => 'files.flash.upload_invalid',
            'chunk_upload.chunk_order' => 'files.flash.upload_invalid',
            'chunk_upload.folder_invalid' => 'files.flash.target_folder_invalid',
            'chunk_upload.size_overflow', 'chunk_upload.too_large' => 'files.flash.upload_too_large',
            'chunk_upload.quota_exceeded', 'storage_quota.exceeded' => 'files.flash.quota_exceeded',
            'chunk_upload.incomplete' => 'files.flash.upload_invalid',
            'chunk_upload.name_conflict' => 'files.flash.upload_name_conflict',
            'chunk_upload.part_missing' => 'files.flash.upload_invalid',
            'chunk_upload.storage_failed', 'chunk_upload.append_failed', 'chunk_upload.part_init_failed', 'chunk_upload.meta_write_failed', 'chunk_upload.mkdir_failed' => 'files.flash.storage_failed',
            default => 'files.flash.upload_invalid',
        };
    }

    /**
     * @brief JSON error or flash+redirect for chunk endpoints (mirrors uploadJsonErrorOrRedirect).
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator.
     * @param string $messageKey Flash message key.
     * @param int $httpStatus HTTP status for JSON clients.
     * @return JsonResponse|Response
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function chunkUploadJsonOrRedirect(Request $request, TranslatorInterface $translator, string $messageKey, int $httpStatus): JsonResponse|Response
    {
        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $translator->trans($messageKey, [], 'messages'),
            ], $httpStatus);
        }
        $this->addFlash('danger', $messageKey);

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Read current share state for a single owned file with the decoupled public/friends payload (public.expires_at only when the public link is active; expires_at as ISO-8601 instant for browser-local datetime-local prefill).
     * @param Request $request HTTP request.
     * @param int $id Shared file identifier.
     * @param TranslatorInterface $translator Translator for fallback labels.
     * @return JsonResponse
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/files/{id}/share/state', name: 'files_share_state', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function shareState(Request $request, int $id, TranslatorInterface $translator): JsonResponse
    {
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request, true);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        if (
            !$sharedFile instanceof SharedFile
            || $sharedFile->getOwnerUserId() !== $ownerId
        ) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        $grants = $this->shareGrantRepository->findActiveBySharedFile((int) $sharedFile->getId());
        $userIds = array_map(static fn (ShareGrant $g): int => $g->getGranteeUserId(), $grants);
        $labelMap = [];
        foreach ($this->userRepository->findByIdsOrdered($userIds) as $u) {
            $pseudo = $u->getPseudonym();
            $labelMap[(int) $u->getId()] = $pseudo !== ''
                ? $pseudo
                : $translator->trans('files.upload.grantee_label_fallback', [], 'messages');
        }

        $friendsRows = [];
        foreach ($grants as $grant) {
            $uid = $grant->getGranteeUserId();
            $friendsRows[] = [
                'user_id' => $uid,
                'label' => $labelMap[$uid] ?? (string) $uid,
                'expires_at' => $grant->getExpiresAt()?->format('Y-m-d\TH:i'),
                'expired' => false,
            ];
        }

        $pwdPlain = null;
        $isAdminActor = $this->isGranted('ROLE_ADMIN');
        $isGodviewProxy = $isAdminActor && (int) $user->getId() !== $ownerId;
        $passwordCopyAvailable = !$isGodviewProxy && $sharedFile->isPublicPasswordEnabled() && $sharedFile->isPublicShareActive();
        if ($passwordCopyAvailable) {
            $pwdPlain = $this->publicShareResourcePasswordService->decryptPlainForOwnerSharedFile($sharedFile);
        }

        return new JsonResponse([
            'status' => 'ok',
            'id' => (int) $sharedFile->getId(),
            'name' => $sharedFile->getOriginalFileName(),
            'public' => [
                'enabled' => $sharedFile->isPublicShareActive(),
                'active' => $sharedFile->isPublicShareActive(),
                'expired' => $sharedFile->isPublicExpired(),
                'expires_at' => $sharedFile->isPublicShareActive()
                    ? $sharedFile->getEffectivePublicExpiresAtForOwnerUi()?->format(\DateTimeInterface::ATOM)
                    : null,
                'token' => ($sharedFile->isPublicShareActive() && !$isGodviewProxy) ? $sharedFile->getPublicToken() : null,
                'password_enabled' => $sharedFile->isPublicPasswordEnabled() && $sharedFile->isPublicShareActive(),
                'password_plain' => $pwdPlain,
                'password_copy_available' => $passwordCopyAvailable,
            ],
            'friends' => $friendsRows,
        ]);
    }

    /**
     * @brief Read full owner-side properties of a single shared file with the decoupled public/friends payload.
     * @param Request $request HTTP request.
     * @param int $id Shared file identifier.
     * @param TranslatorInterface $translator Translator used to resolve grantee fallback labels.
     * @return JsonResponse JSON payload describing the file properties or an error envelope.
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/{id}/properties', name: 'files_properties', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function properties(Request $request, int $id, TranslatorInterface $translator): JsonResponse
    {
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request, true);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        if (
            !$sharedFile instanceof SharedFile
            || $sharedFile->getOwnerUserId() !== $ownerId
        ) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        $activeGrants = $this->shareGrantRepository->findActiveBySharedFile((int) $sharedFile->getId());
        $granteeUserIds = array_map(static fn (ShareGrant $g): int => $g->getGranteeUserId(), $activeGrants);
        $labelMap = [];
        foreach ($this->userRepository->findByIdsOrdered($granteeUserIds) as $u) {
            $pseudo = $u->getPseudonym();
            $labelMap[(int) $u->getId()] = $pseudo !== ''
                ? $pseudo
                : $translator->trans('files.upload.grantee_label_fallback', [], 'messages');
        }
        $friendsPayload = [];
        foreach ($activeGrants as $grant) {
            $uid = $grant->getGranteeUserId();
            $friendsPayload[] = [
                'user_id' => $uid,
                'pseudo' => $labelMap[$uid] ?? (string) $uid,
                'expires_at' => $grant->getExpiresAt()?->format(DATE_ATOM),
            ];
        }

        $extension = strtolower($sharedFile->getFileExtension());
        $previewExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif', 'ico'];
        $previewUrl = \in_array($extension, $previewExtensions, true)
            ? $this->generateUrl('files_download', ['id' => (int) $sharedFile->getId()])
            : null;

        $byteSize = (int) $sharedFile->getByteSize();
        $hasFriendsExpiration = $this->friendsShareService->hasAnyGrantExpiration($sharedFile);
        $ownerUser = $this->userRepository->find($ownerId);
        $sharedByLabel = $ownerUser instanceof User
            ? ($ownerUser->getPseudonym() !== '' ? $ownerUser->getPseudonym() : $ownerUser->getEmail())
            : (string) $ownerId;
        $pubPwdPlain = null;
        $isAdminActor = $this->isGranted('ROLE_ADMIN');
        $isGodviewProxy = $isAdminActor && (int) $user->getId() !== $ownerId;
        if (!$isGodviewProxy && $sharedFile->isPublicPasswordEnabled() && $sharedFile->isPublicShareActive()) {
            $pubPwdPlain = $this->publicShareResourcePasswordService->decryptPlainForOwnerSharedFile($sharedFile);
        }

        return new JsonResponse([
            'status' => 'ok',
            'id' => (int) $sharedFile->getId(),
            'name' => $sharedFile->getOriginalFileName(),
            'shared_by' => $sharedByLabel,
            'extension' => $extension,
            'byte_size' => $byteSize,
            'byte_size_formatted' => $this->binaryByteFormatter->format($byteSize),
            'uploaded_at' => $sharedFile->getUploadedAt()->format(DATE_ATOM),
            'updated_at' => $sharedFile->getUpdatedAt()->format(DATE_ATOM),
            'public' => [
                'enabled' => $sharedFile->isPublicShareActive(),
                'active' => $sharedFile->isPublicShareActive(),
                'expired' => $sharedFile->isPublicExpired(),
                'expires_at' => $sharedFile->isPublicShareActive()
                    ? $sharedFile->getEffectivePublicExpiresAtForOwnerUi()?->format(DATE_ATOM)
                    : null,
                'token' => ($sharedFile->isPublicShareActive() && !$isGodviewProxy) ? $sharedFile->getPublicToken() : null,
                'password_enabled' => $sharedFile->isPublicPasswordEnabled() && $sharedFile->isPublicShareActive(),
                'password_plain' => $pubPwdPlain,
            ],
            'friends' => [
                'count' => count($friendsPayload),
                'has_any_expiration' => $hasFriendsExpiration,
                'grants' => $friendsPayload,
            ],
            'preview_url' => $previewUrl,
        ]);
    }

    /**
     * @brief Read grantee-side properties for one shared file with limited share visibility.
     * @param int $id Shared file identifier.
     * @param TranslatorInterface $translator Translator for fallback labels.
     * @return JsonResponse
     * @date 2026-04-30
     * @author Stephane H.
     */
    #[Route('/files/shared/{id}/properties', name: 'files_shared_properties', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function sharedProperties(int $id, TranslatorInterface $translator): JsonResponse
    {
        $sharedFile = $this->sharedFileRepository->find($id);
        if (!$sharedFile instanceof SharedFile) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }
        /** @var User $user */
        $user = $this->getUser();
        $requesterId = (int) $user->getId();

        $allowed = false;
        if ($this->isGranted('ROLE_ADMIN')) {
            $allowed = true;
        } elseif ($sharedFile->getOwnerUserId() === $requesterId && $this->isGranted('ROLE_SHARE_SEND')) {
            $allowed = true;
        } else {
            $hasGrant = $this->shareGrantRepository->hasGrantForUser((int) $sharedFile->getId(), $requesterId);
            $allowed = $this->shareAuthorizationService->canAccessPrivateByUser($sharedFile, $requesterId, $hasGrant);
        }
        if (!$allowed) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }
        $ownerUser = $this->userRepository->find($sharedFile->getOwnerUserId());
        $sharedByLabel = $ownerUser instanceof User
            ? ($ownerUser->getPseudonym() !== '' ? $ownerUser->getPseudonym() : $ownerUser->getEmail())
            : (string) $sharedFile->getOwnerUserId();
        $extension = strtolower($sharedFile->getFileExtension());
        $previewExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif', 'ico'];
        $previewUrl = \in_array($extension, $previewExtensions, true)
            ? $this->generateUrl('files_download', ['id' => (int) $sharedFile->getId()])
            : null;

        $byteSize = (int) $sharedFile->getByteSize();
        $friendLabel = $user->getPseudonym() !== ''
            ? $user->getPseudonym()
            : $translator->trans('files.upload.grantee_label_fallback', [], 'messages');
        $friendsPayload = [[
            'user_id' => $requesterId,
            'pseudo' => $friendLabel,
            'expires_at' => null,
        ]];

        return new JsonResponse([
            'status' => 'ok',
            'id' => (int) $sharedFile->getId(),
            'name' => $sharedFile->getOriginalFileName(),
            'extension' => $extension,
            'byte_size' => $byteSize,
            'byte_size_formatted' => $this->binaryByteFormatter->format($byteSize),
            'uploaded_at' => $sharedFile->getUploadedAt()->format(DATE_ATOM),
            'updated_at' => $sharedFile->getUpdatedAt()->format(DATE_ATOM),
            'public' => [
                'enabled' => false,
                'active' => false,
                'expired' => false,
                'expires_at' => null,
                'token' => null,
            ],
            'friends' => [
                'count' => count($friendsPayload),
                'has_any_expiration' => false,
                'grants' => $friendsPayload,
            ],
            'preview_url' => $previewUrl,
        ]);
    }

    /**
     * @brief Apply a public-channel intention (enable/disable + optional expiration) to a single owned file.
     * @param Request $request HTTP request (form-encoded).
     * @param int $id Shared file identifier.
     * @return Response JSON or redirect depending on the Accept header.
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/{id}/share/public', name: 'files_share_public', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function sharePublic(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_SHARE_PUBLIC, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }
        if (!$this->isGranted('ROLE_SHARE_PUBLIC')) {
            return $this->shareJsonOrRedirect($request, 'files.flash.public_not_allowed', 403);
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }
        if (!$sharedFile instanceof SharedFile) {
            return $this->shareJsonOrRedirect($request, 'files.flash.not_found', 404);
        }
        $isAdminActor = $this->isGranted('ROLE_ADMIN');
        if ($sharedFile->getOwnerUserId() !== $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.not_found', 404);
        }

        $payload = $this->extractPublicSharePayload($request);
        $passwordPlain = null;
        if ($payload['enabled']) {
            $report = $this->publicShareService->enablePublic($sharedFile, $payload['expires_at']);
            $passwordPlain = $this->syncPublicFilePassword($sharedFile, $payload);
            $this->entityManager->flush();
            $action = $payload['expires_at'] !== null ? 'expires' : 'enable';
        } else {
            $report = $this->publicShareService->disablePublic($sharedFile);
        }

        if ($this->expectsJson($request)) {
            $out = ['status' => 'ok', 'id' => $id, 'report' => $report];
            if ($passwordPlain !== null) {
                $out['public_password_plain'] = $passwordPlain;
            }

            return new JsonResponse($out);
        }
        $this->addFlash('success', 'files.flash.share_applied');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Toggle the public password gate for one owned file without applying full public share submit.
     * @param Request $request HTTP request (form-encoded).
     * @param int $id Shared file identifier.
     * @return JsonResponse
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/files/{id}/share/public/password-toggle', name: 'files_share_public_password_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function sharePublicPasswordToggle(Request $request, int $id): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_SHARE_PUBLIC, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 400);
        }
        if (!$this->isGranted('ROLE_SHARE_PUBLIC')) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.public_not_allowed'], 403);
        }
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $sharedFile = $this->sharedFileRepository->find($id);
        if (!$sharedFile instanceof SharedFile || $sharedFile->getOwnerUserId() !== $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        $passwordEnabled = filter_var($request->request->get('public_password_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        $passwordPlain = $this->publicShareResourcePasswordService->applyToSharedFile($sharedFile, $passwordEnabled);
        $this->entityManager->flush();

        $out = [
            'status' => 'ok',
            'password_enabled' => $passwordEnabled,
        ];
        if ($passwordEnabled && $passwordPlain !== null && $passwordPlain !== '') {
            $out['public_password_plain'] = $passwordPlain;
        }

        return new JsonResponse($out);
    }

    /**
     * @brief Apply a friends-channel intention (per-grantee grants with their own expiration, merge or replace) to a single owned file.
     * @param Request $request HTTP request (form-encoded).
     * @param int $id Shared file identifier.
     * @return Response JSON or redirect depending on the Accept header.
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/{id}/share/friends', name: 'files_share_friends', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function shareFriends(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_SHARE_FRIENDS, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }
        if (!$this->isGranted('ROLE_SHARE_FRIENDS')) {
            return $this->shareJsonOrRedirect($request, 'files.flash.public_not_allowed', 403);
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }
        if (!$sharedFile instanceof SharedFile || $sharedFile->getOwnerUserId() !== $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.not_found', 404);
        }

        $payload = $this->extractFriendsSharePayload($request);
        $report = $this->friendsShareService->applyFriendsIntent($sharedFile, $payload['grantees'], $payload['replace_existing']);

        if ($this->expectsJson($request)) {
            return new JsonResponse(['status' => 'ok', 'id' => $id, 'report' => $report]);
        }
        $this->addFlash('success', 'files.flash.share_applied');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Apply a public-channel intention to a batch of owned files; non-owned ids are reported in 'failed'.
     * @param Request $request HTTP request (form-encoded).
     * @return Response JSON or redirect depending on the Accept header.
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/share/bulk/public', name: 'files_share_bulk_public', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function shareBulkPublic(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_SHARE_BULK_PUBLIC, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }
        if (!$this->isGranted('ROLE_SHARE_PUBLIC')) {
            return $this->shareJsonOrRedirect($request, 'files.flash.public_not_allowed', 403);
        }

        $targetIds = $this->extractBulkTargetIds($request);
        if ($targetIds === []) {
            return $this->shareJsonOrRedirect($request, 'files.flash.bulk_no_ids', 400);
        }

        $payload = $this->extractPublicSharePayload($request);
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }
        $actorId = (int) $user->getId();

        $okIds = [];
        $failed = [];
        foreach ($targetIds as $sharedFileId) {
            $sharedFile = $this->sharedFileRepository->find($sharedFileId);
            if (!$sharedFile instanceof SharedFile) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_found'];
                continue;
            }
            if ($sharedFile->getOwnerUserId() !== $ownerId) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_owner'];
                continue;
            }
            if ($payload['enabled']) {
                $this->publicShareService->enablePublic($sharedFile, $payload['expires_at']);
                $this->publicShareResourcePasswordService->applyToSharedFile($sharedFile, false);
            } else {
                $this->publicShareService->disablePublic($sharedFile);
            }
            $okIds[] = (int) $sharedFile->getId();
        }
        $this->entityManager->flush();
        if ($okIds !== []) {
        }

        if ($this->expectsJson($request)) {
            return new JsonResponse(['status' => 'ok', 'ok' => $okIds, 'failed' => $failed]);
        }
        if ($okIds !== []) {
            $this->addFlash('success', 'files.flash.bulk_share_ok');
        }
        if ($failed !== []) {
            $this->addFlash('warning', 'files.flash.bulk_share_partial');
        }

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Sync optional public-download password for a file share from modal payload.
     * @param SharedFile $sharedFile Target aggregate (public channel already enabled when called).
     * @param array{enabled: bool, expires_at: mixed, public_password_enabled: bool} $payload Share payload.
     * @return string|null Plain password for JSON owner response when generated or decrypted.
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function syncPublicFilePassword(SharedFile $sharedFile, array $payload): ?string
    {
        if (empty($payload['public_password_enabled'])) {
            $this->publicShareResourcePasswordService->applyToSharedFile($sharedFile, false);

            return null;
        }
        $hash = $sharedFile->getPublicPasswordHash();
        if (!$sharedFile->isPublicPasswordEnabled() || $hash === null || $hash === '') {
            return $this->publicShareResourcePasswordService->applyToSharedFile($sharedFile, true);
        }

        return $this->publicShareResourcePasswordService->decryptPlainForOwnerSharedFile($sharedFile);
    }

    /**
     * @brief Sync optional public-download password for a folder share.
     * @param Folder $folder Target folder.
     * @param array{enabled: bool, expires_at: mixed, public_password_enabled: bool} $payload Share payload.
     * @return string|null Plain password for owner JSON.
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function syncPublicFolderPassword(Folder $folder, array $payload): ?string
    {
        if (empty($payload['public_password_enabled'])) {
            $this->publicShareResourcePasswordService->applyToFolder($folder, false);

            return null;
        }
        $hash = $folder->getPublicPasswordHash();
        if (!$folder->isPublicPasswordEnabled() || $hash === null || $hash === '') {
            return $this->publicShareResourcePasswordService->applyToFolder($folder, true);
        }

        return $this->publicShareResourcePasswordService->decryptPlainForOwnerFolder($folder);
    }

    /**
     * @brief Apply a friends-channel intention to a batch of owned files; non-owned ids are reported in 'failed'.
     * @param Request $request HTTP request (form-encoded).
     * @return Response JSON or redirect depending on the Accept header.
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/share/bulk/friends', name: 'files_share_bulk_friends', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function shareBulkFriends(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_SHARE_BULK_FRIENDS, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }
        if (!$this->isGranted('ROLE_SHARE_FRIENDS')) {
            return $this->shareJsonOrRedirect($request, 'files.flash.public_not_allowed', 403);
        }

        $targetIds = $this->extractBulkTargetIds($request);
        if ($targetIds === []) {
            return $this->shareJsonOrRedirect($request, 'files.flash.bulk_no_ids', 400);
        }

        $payload = $this->extractFriendsSharePayload($request);
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }
        $actorId = (int) $user->getId();

        $okIds = [];
        $failed = [];
        foreach ($targetIds as $sharedFileId) {
            $sharedFile = $this->sharedFileRepository->find($sharedFileId);
            if (!$sharedFile instanceof SharedFile) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_found'];
                continue;
            }
            if ($sharedFile->getOwnerUserId() !== $ownerId) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_owner'];
                continue;
            }
            $this->friendsShareService->applyFriendsIntent($sharedFile, $payload['grantees'], $payload['replace_existing']);
            $okIds[] = (int) $sharedFile->getId();
        }
        if ($okIds !== []) {
        }

        if ($this->expectsJson($request)) {
            return new JsonResponse(['status' => 'ok', 'ok' => $okIds, 'failed' => $failed]);
        }
        if ($okIds !== []) {
            $this->addFlash('success', 'files.flash.bulk_share_ok');
        }
        if ($failed !== []) {
            $this->addFlash('warning', 'files.flash.bulk_share_partial');
        }

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Delete a batch of owned files and/or owned folders (subtrees); non-owned file ids in 'failed'.
     * @param Request $request HTTP request (form-encoded, ids[] and optional folder_ids[]).
     * @return Response JSON or redirect depending on the Accept header.
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/files/delete/bulk', name: 'files_delete_bulk', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function deleteBulk(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_DELETE_BULK, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }

        $targetIds = $this->extractBulkTargetIds($request);
        $bulkFolderIds = $this->extractBulkFolderIds($request);
        if ($targetIds === [] && $bulkFolderIds === []) {
            return $this->shareJsonOrRedirect($request, 'files.flash.bulk_no_ids', 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $actorId = (int) $user->getId();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }

        $okIds = [];
        $failed = [];
        foreach ($targetIds as $sharedFileId) {
            $sharedFile = $this->sharedFileRepository->find($sharedFileId);
            if (!$sharedFile instanceof SharedFile) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_found'];
                continue;
            }
            if ($sharedFile->getOwnerUserId() !== $ownerId) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_owner'];
                continue;
            }

            $this->removeSharedFileAggregate($sharedFile);
            $okIds[] = $sharedFileId;
        }
        if ($okIds !== []) {
        }

        $okFolderIds = [];
        $sortedFolderIds = $this->sortOwnedFolderIdsDeepestFirst($ownerId, $bulkFolderIds);
        foreach ($sortedFolderIds as $folderId) {
            $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $folderId);
            if (!$folder instanceof Folder) {
                continue;
            }
            $this->deleteOwnedFolderSubtree($ownerId, $folder);
            $okFolderIds[] = $folderId;
        }

        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'status' => 'ok',
                'ok' => $okIds,
                'failed' => $failed,
                'ok_folders' => $okFolderIds,
                'failed_folders' => [],
            ]);
        }
        if ($okIds !== [] || $okFolderIds !== []) {
            $this->addFlash('success', 'files.flash.bulk_delete_ok');
        }
        if ($failed !== []) {
            $this->addFlash('warning', 'files.flash.bulk_delete_partial');
        }

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief JSON list of immediate child folders for drill-down folder picker (owned tree).
     * @param Request $request Query parent (0 or absent = root).
     * @return JsonResponse Folder rows with id and name.
     * @date 2026-05-03
     * @author Stephane H.
     */
    #[Route('/files/folders/children', name: 'files_folder_children', methods: ['GET'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function folderChildren(Request $request): JsonResponse
    {
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request, true);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $parentRaw = $request->query->get('parent', '0');
        $parentId = is_numeric($parentRaw) ? (int) $parentRaw : 0;

        $parentFolder = null;
        if ($parentId > 0) {
            $parentFolder = $this->folderTreeService->resolveCurrentFolder($ownerId, $parentId);
            if (!$parentFolder instanceof Folder) {
                return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
            }
        }

        $children = $this->folderRepository->findChildrenForOwner($ownerId, $parentFolder);
        $rows = [];
        foreach ($children as $child) {
            if (!$child instanceof Folder || $child->getId() === null) {
                continue;
            }
            $rows[] = [
                'id' => (int) $child->getId(),
                'name' => $child->getName(),
            ];
        }

        return new JsonResponse(['status' => 'ok', 'folders' => $rows]);
    }

    /**
     * @brief Move selected owned files and/or folders into a target folder (or root).
     * @param Request $request POST ids[], folder_ids[], target_folder_id (0 = root).
     * @return Response JSON or redirect.
     * @date 2026-05-03
     * @author Stephane H.
     */
    #[Route('/files/move/bulk', name: 'files_move_bulk', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function moveBulk(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_MOVE_BULK, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }

        $targetRaw = $request->request->get('target_folder_id', '0');
        $targetFolderId = is_numeric($targetRaw) ? (int) $targetRaw : 0;

        /** @var User $user */
        $user = $this->getUser();
        $actorId = (int) $user->getId();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request, false);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }

        $targetFolder = null;
        if ($targetFolderId > 0) {
            $targetFolder = $this->folderTreeService->resolveCurrentFolder($ownerId, $targetFolderId);
            if (!$targetFolder instanceof Folder) {
                return $this->shareJsonOrRedirect($request, 'files.flash.move_target_invalid', 400);
            }
        }

        $fileIds = $this->extractBulkTargetIds($request);
        $bulkFolderIds = $this->extractBulkFolderIds($request);
        if ($fileIds === [] && $bulkFolderIds === []) {
            return $this->shareJsonOrRedirect($request, 'files.flash.bulk_no_ids', 400);
        }

        $okIds = [];
        $failed = [];
        foreach ($fileIds as $sharedFileId) {
            $sharedFile = $this->sharedFileRepository->find($sharedFileId);
            if (!$sharedFile instanceof SharedFile) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_found'];
                continue;
            }
            if ($sharedFile->getOwnerUserId() !== $ownerId) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.not_owner'];
                continue;
            }
            $normalized = Folder::normalizeName($sharedFile->getOriginalFileName());
            if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerId, $targetFolder, $normalized, $sharedFileId) instanceof SharedFile) {
                $failed[] = ['id' => $sharedFileId, 'reason_key' => 'files.flash.move_name_conflict'];
                continue;
            }
            $sharedFile->setFolder($targetFolder);
            $okIds[] = $sharedFileId;
        }

        $okFolderIds = [];
        $failedFolders = [];
        $sortedFolderIds = $this->sortOwnedFolderIdsDeepestFirst($ownerId, $bulkFolderIds);
        foreach ($sortedFolderIds as $folderId) {
            $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $folderId);
            if (!$folder instanceof Folder) {
                $failedFolders[] = ['id' => $folderId, 'reason_key' => 'files.flash.not_found'];
                continue;
            }
            if ($this->isMoveTargetInsideFolderSubtree($ownerId, $folder, $targetFolder)) {
                $failedFolders[] = ['id' => $folderId, 'reason_key' => 'files.flash.move_folder_into_self_or_descendant'];
                continue;
            }
            $normalizedFolderName = Folder::normalizeName($folder->getName());
            if ($this->siblingFolderExistsWithNormalizedName($ownerId, $targetFolder, $normalizedFolderName, $folderId)) {
                $failedFolders[] = ['id' => $folderId, 'reason_key' => 'files.flash.move_folder_name_conflict'];
                continue;
            }
            $folder->setParent($targetFolder);
            $okFolderIds[] = $folderId;
        }

        $this->entityManager->flush();

        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'status' => 'ok',
                'ok' => $okIds,
                'failed' => $failed,
                'ok_folders' => $okFolderIds,
                'failed_folders' => $failedFolders,
            ]);
        }
        if ($failed !== [] || $failedFolders !== []) {
            $this->addFlash('warning', 'files.flash.move_bulk_partial');
        } elseif ($okIds !== [] || $okFolderIds !== []) {
            $this->addFlash('success', 'files.flash.move_bulk_ok');
        }

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Build a JSON or redirect response for share endpoints depending on Accept header.
     * @param Request $request HTTP request.
     * @param string $messageKey Translation key for the flash or JSON message.
     * @param int $statusCode HTTP status to use when answering JSON.
     * @return Response
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function shareJsonOrRedirect(Request $request, string $messageKey, int $statusCode): Response
    {
        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $this->translator->trans($messageKey, [], 'messages', (string) $request->getLocale()),
                'message_key' => $messageKey,
            ], $statusCode);
        }
        $this->addFlash('danger', $messageKey);

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Return JSON error with translated message for XHR upload, or flash+redirect for full form post.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator for JSON message body.
     * @param string $messageKey Message domain key (same as flash key for HTML path).
     * @param int $httpStatus HTTP status for JSON error responses.
     * @return Response
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function uploadJsonErrorOrRedirect(Request $request, TranslatorInterface $translator, string $messageKey, int $httpStatus = 400): Response
    {
        if ($this->expectsJson($request)) {
            $locale = (string) $request->getLocale();
            return new JsonResponse([
                'status' => 'error',
                'message' => $translator->trans($messageKey, [], 'messages', $locale),
            ], $httpStatus);
        }
        $this->addFlash('danger', $messageKey);

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Return JSON success with translated message for XHR upload (no session flash), or flash+redirect for full form post.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator for JSON success message body.
     * @return Response
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function uploadSuccessJsonOrRedirect(Request $request, TranslatorInterface $translator): Response
    {
        $listingParams = $this->extractListingRouteParams($request);
        $route = $this->listingRedirectRouteName($listingParams);
        if ($this->expectsJson($request)) {
            $locale = (string) $request->getLocale();
            return new JsonResponse([
                'status' => 'ok',
                'message' => $translator->trans('files.flash.uploaded', [], 'messages', $locale),
                'redirect' => $this->generateUrl($route, $listingParams, UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        }
        $this->addFlash('success', 'files.flash.uploaded');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Build rename endpoint response as JSON for AJAX or as flash+redirect for full POST.
     * @param Request $request HTTP request.
     * @param string $message User-facing translated message.
     * @param int $statusCode HTTP status code.
     * @param string $flashType Flash severity ('success' or 'danger').
     * @return Response
     * @date 2026-04-30
     * @author Stephane H.
     */
    private function renameJsonOrRedirect(Request $request, string $message, int $statusCode, string $flashType): Response
    {
        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'status' => $statusCode >= 400 ? 'error' : 'ok',
                'message' => $message,
            ], $statusCode);
        }
        $this->addFlash($flashType, $message);

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Whether a sibling folder (same parent) already uses the normalized name, optionally excluding one folder.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder|null $parentFolder Parent folder or null for root.
     * @param string $normalizedName Name already normalized with Folder::normalizeName.
     * @param int|null $excludeFolderId Folder id to ignore when renaming in place.
     * @return bool
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function siblingFolderExistsWithNormalizedName(
        int $ownerUserId,
        ?Folder $parentFolder,
        string $normalizedName,
        ?int $excludeFolderId
    ): bool {
        $existing = $this->folderRepository->findOneByOwnerParentAndNormalizedName($ownerUserId, $parentFolder, $normalizedName);
        if (!$existing instanceof Folder) {
            return false;
        }
        $eid = $existing->getId();
        if ($excludeFolderId !== null && (int) $eid === (int) $excludeFolderId) {
            return false;
        }

        return true;
    }

    /**
     * @brief Whether moving $folder into $targetFolder would place it under itself or a descendant (invalid).
     * @param int $ownerUserId Owner user id.
     * @param Folder $folder Folder being moved.
     * @param Folder|null $targetFolder Destination parent; null = root (always valid for this check).
     * @return bool True when the move must be rejected.
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function isMoveTargetInsideFolderSubtree(int $ownerUserId, Folder $folder, ?Folder $targetFolder): bool
    {
        if (!$targetFolder instanceof Folder) {
            return false;
        }
        foreach ($this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder) as $node) {
            if ((int) $node->getId() === (int) $targetFolder->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Read the public-channel form payload from a POST request body.
     * @param Request $request HTTP request.
     * @return array{enabled: bool, expires_at: \DateTimeImmutable|null}
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function extractPublicSharePayload(Request $request): array
    {
        $rawEnabled = $request->request->get('enabled', $request->request->get('is_public'));
        $enabled = filter_var((string) $rawEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            $enabled = trim((string) $rawEnabled) !== '';
        }
        $expiresAt = $this->parseOptionalExpiresAt((string) $request->request->get('public_expires_at', $request->request->get('expires_at', '')));
        $rawPwd = $request->request->get('public_password_enabled', '0');
        $publicPasswordEnabled = filter_var((string) $rawPwd, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($publicPasswordEnabled === null) {
            $publicPasswordEnabled = trim((string) $rawPwd) !== '' && (string) $rawPwd !== '0';
        }

        return [
            'enabled' => (bool) $enabled,
            'expires_at' => $expiresAt,
            'public_password_enabled' => (bool) $publicPasswordEnabled,
        ];
    }

    /**
     * @brief Read the friends-channel form payload from a POST request body.
     * @param Request $request HTTP request.
     * @return array{grantees: array<int, array{user_id: int, expires_at: string}>, replace_existing: bool}
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function extractFriendsSharePayload(Request $request): array
    {
        $intents = [];
        $rawList = $request->request->all()['grantees'] ?? [];
        if (is_array($rawList)) {
            foreach ($rawList as $row) {
                if (is_array($row)) {
                    $intents[] = [
                        'user_id' => (int) ($row['user_id'] ?? 0),
                        'expires_at' => isset($row['expires_at']) ? trim((string) $row['expires_at']) : '',
                    ];
                }
            }
        }
        if ($intents === []) {
            $legacyRaw = trim((string) $request->request->get('grantee_ids', ''));
            if ($legacyRaw !== '') {
                foreach ($this->parseGranteeIdList($legacyRaw) as $gid) {
                    $intents[] = ['user_id' => $gid, 'expires_at' => ''];
                }
            }
        }

        $replaceExisting = filter_var((string) $request->request->get('replace_existing', '0'), FILTER_VALIDATE_BOOLEAN);

        return [
            'grantees' => $intents,
            'replace_existing' => (bool) $replaceExisting,
        ];
    }

    /**
     * @brief Read and normalize the bulk target id list from a POST request body.
     * @param Request $request HTTP request.
     * @return array<int, int>
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function extractBulkTargetIds(Request $request): array
    {
        $idsRaw = $request->request->all()['ids'] ?? [];
        if (!is_array($idsRaw)) {
            $idsRaw = [];
        }
        $targetIds = [];
        foreach ($idsRaw as $token) {
            $tokenInt = (int) $token;
            if ($tokenInt > 0) {
                $targetIds[$tokenInt] = $tokenInt;
            }
        }

        return array_values($targetIds);
    }

    /**
     * @brief Read and normalize bulk folder id list from a POST body (folder_ids[]).
     * @param Request $request HTTP request.
     * @return array<int, int>
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function extractBulkFolderIds(Request $request): array
    {
        $idsRaw = $request->request->all()['folder_ids'] ?? [];
        if (!is_array($idsRaw)) {
            $idsRaw = [];
        }
        $out = [];
        foreach ($idsRaw as $token) {
            $tokenInt = (int) $token;
            if ($tokenInt > 0) {
                $out[$tokenInt] = $tokenInt;
            }
        }

        return array_values($out);
    }

    /**
     * @brief Resolve owner user id for bulk/delete/move godview: ROLE_ADMIN may pass subject_user (target account).
     * @param Request $request HTTP request.
     * @param bool $fromQuery When true read subject_user from query string (GET); else from POST body.
     * @return int|null Effective owner id, or null when godview all-users misses a valid subject_user.
     * @date 2026-05-05
     * @author Stephane H.
     */
    private function tryResolveEffectiveOwnerIdForAdminSubject(Request $request, bool $fromQuery = false): ?int
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $selfId = (int) $actor->getId();
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $selfId;
        }
        $adminContextRaw = $fromQuery ? $request->query->get('admin_context') : $request->request->get('admin_context');
        $adminViewScopeRaw = $fromQuery ? $request->query->get('admin_view_scope') : $request->request->get('admin_view_scope');
        $isGodviewAllUsers = (string) $adminContextRaw === '1' && (string) $adminViewScopeRaw === 'all';
        $raw = $fromQuery ? $request->query->get('subject_user') : $request->request->get('subject_user');
        if ($raw === null || '' === trim((string) $raw)) {
            return $isGodviewAllUsers ? null : $selfId;
        }
        $subjectId = (int) $raw;
        if ($subjectId <= 0) {
            return $isGodviewAllUsers ? null : $selfId;
        }
        $subject = $this->userRepository->find($subjectId);

        if (!$subject instanceof User) {
            return $isGodviewAllUsers ? null : $selfId;
        }

        return $subjectId;
    }

    /**
     * @brief Sort owned folder ids so deeper descendants are deleted before ancestors (bulk safety).
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers (deduped).
     * @return array<int, int> Ordered folder ids.
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function sortOwnedFolderIdsDeepestFirst(int $ownerUserId, array $folderIds): array
    {
        $scored = [];
        foreach ($folderIds as $fid) {
            $folder = $this->folderTreeService->resolveCurrentFolder($ownerUserId, $fid);
            if (!$folder instanceof Folder) {
                continue;
            }
            $depth = 0;
            for ($c = $folder; $c->getParent() !== null; $c = $c->getParent()) {
                ++$depth;
            }
            $scored[] = ['id' => $fid, 'depth' => $depth];
        }
        usort($scored, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);

        return array_map(static fn (array $row): int => $row['id'], $scored);
    }

    /**
     * @brief Delete one folder subtree: shared files aggregates then folder rows (deepest entities first).
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $rootFolder Root folder to remove with descendants.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function deleteOwnedFolderSubtree(int $ownerUserId, Folder $rootFolder): void
    {
        $folders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $rootFolder);
        foreach ($folders as $subFolder) {
            $files = $this->sharedFileRepository->findBy(['ownerUserId' => $ownerUserId, 'folder' => $subFolder]);
            foreach ($files as $file) {
                $this->removeSharedFileAggregate($file);
            }
        }
        for ($i = count($folders) - 1; $i >= 0; --$i) {
            $this->entityManager->remove($folders[$i]);
        }
        $this->entityManager->flush();
    }

    /**
     * @brief Tell whether the caller expects a JSON response (X-Requested-With or Accept negotiation).
     * @param Request $request HTTP request.
     * @return bool
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function expectsJson(Request $request): bool
    {
        if (strtolower((string) $request->headers->get('X-Requested-With')) === 'xmlhttprequest') {
            return true;
        }
        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json');
    }

    /**
     * @brief Delete an owned shared file row and encrypted payload.
     * @param Request $request HTTP request.
     * @param int $id Shared file identifier.
     * @return Response
     * @date 2026-04-27
     * @author Stephane H.
     */
    #[Route('/files/{id}/delete', name: 'files_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_DELETE, (string) $request->request->get('_csrf_token', '')))) {
            $this->addFlash('danger', 'files.flash.csrf_invalid');

            return $this->redirectToFilesIndex($request);
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        if (!$sharedFile instanceof SharedFile) {
            $this->addFlash('danger', 'files.flash.not_found');

            return $this->redirectToFilesIndex($request);
        }
        $isAdminActor = $this->isGranted('ROLE_ADMIN');
        if ($sharedFile->getOwnerUserId() !== (int) $user->getId() && !$isAdminActor) {
            $this->addFlash('danger', 'files.flash.not_found');

            return $this->redirectToFilesIndex($request);
        }

        $this->removeSharedFileAggregate($sharedFile);
        $this->addFlash('success', 'files.flash.deleted');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Rename an owned shared file and persist the updated display name.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator for JSON feedback messages.
     * @param int $id Shared file identifier.
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route('/files/{id}/rename', name: 'files_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function rename(Request $request, TranslatorInterface $translator, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_RENAME, (string) $request->request->get('_csrf_token', '')))) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.csrf_invalid'), 400, 'danger');
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        if (!$sharedFile instanceof SharedFile) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.not_owner'), 403, 'danger');
        }
        $isAdminActor = $this->isGranted('ROLE_ADMIN');
        $ownerId = $sharedFile->getOwnerUserId() === (int) $user->getId()
            ? (int) $user->getId()
            : (int) $sharedFile->getOwnerUserId();
        if ($sharedFile->getOwnerUserId() !== (int) $user->getId() && !$isAdminActor) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.not_owner'), 403, 'danger');
        }

        $newName = trim((string) $request->request->get('name', ''));
        if ($newName === '') {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.rename_name_required'), 422, 'danger');
        }
        if (mb_strlen($newName) > 255) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.rename_name_too_long'), 422, 'danger');
        }

        $normalizedNew = Folder::normalizeName($newName);
        $fileParent = $sharedFile->getFolder();
        if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerId, $fileParent, $normalizedNew, $id) instanceof SharedFile) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.name_conflict_same_level'), 409, 'danger');
        }
        if ($this->siblingFolderExistsWithNormalizedName($ownerId, $fileParent, $normalizedNew, null)) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.name_conflict_same_level'), 409, 'danger');
        }

        if ($sharedFile->getOriginalFileName() === $newName) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.rename_unchanged'), 200, 'success');
        }

        $sharedFile->setOriginalFileName($newName);
        $this->entityManager->flush();

        return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.renamed'), 200, 'success');
    }

    /**
     * @brief Rename an owned folder and allow admins to target another owner's folder in godview.
     * @param Request $request HTTP request.
     * @param TranslatorInterface $translator Translator for JSON feedback messages.
     * @param int $id Folder identifier.
     * @return Response
     * @date 2026-05-08
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/rename', name: 'files_folder_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function renameFolder(Request $request, TranslatorInterface $translator, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FOLDER_RENAME, (string) $request->request->get('_csrf_token', '')))) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.csrf_invalid'), 400, 'danger');
        }
        /** @var User $user */
        $user = $this->getUser();
        $actorId = (int) $user->getId();
        $folder = $this->folderRepository->find($id);
        if (!$folder instanceof Folder) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.not_found'), 404, 'danger');
        }
        $isAdminActor = $this->isGranted('ROLE_ADMIN');
        $ownerId = (int) $folder->getOwnerUserId();
        if ($ownerId !== $actorId && !$isAdminActor) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.not_owner'), 403, 'danger');
        }

        $trimmed = trim((string) $request->request->get('name', ''));
        if ($trimmed === '') {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.folder_rename_name_required'), 422, 'danger');
        }
        if (mb_strlen($trimmed) > 190) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.folder_rename_name_too_long'), 422, 'danger');
        }

        $effectiveName = mb_substr($trimmed, 0, 190);
        if ($folder->getName() === $effectiveName) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.folder_rename_unchanged'), 200, 'success');
        }

        $normalizedTarget = Folder::normalizeName($effectiveName);
        $collision = $this->folderRepository->findOneByOwnerParentAndNormalizedName(
            $ownerId,
            $folder->getParent(),
            $normalizedTarget
        );
        if ($collision instanceof Folder && $collision->getId() !== $folder->getId()) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.folder_rename_name_conflict'), 409, 'danger');
        }

        if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName(
            $ownerId,
            $folder->getParent(),
            $normalizedTarget,
            null
        ) instanceof SharedFile) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.name_conflict_same_level'), 409, 'danger');
        }

        $folder->setName($trimmed);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.folder_rename_name_conflict'), 409, 'danger');
        }

        return $this->renameJsonOrRedirect($request, $translator->trans('files.flash.folder_renamed'), 200, 'success');
    }

    /**
     * @brief Toggle visibility between private and public when roles allow it.
     * @param Request $request HTTP request.
     * @param int $id Shared file identifier.
     * @return Response
     * @date 2026-04-27
     * @author Stephane H.
     */
    #[Route('/files/{id}/visibility', name: 'files_visibility', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function toggleVisibility(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_VISIBILITY, (string) $request->request->get('_csrf_token', '')))) {
            $this->addFlash('danger', 'files.flash.csrf_invalid');

            return $this->redirectToFilesIndex($request);
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        if (!$sharedFile instanceof SharedFile || $sharedFile->getOwnerUserId() !== (int) $user->getId()) {
            $this->addFlash('danger', 'files.flash.not_found');

            return $this->redirectToFilesIndex($request);
        }

        $target = (string) $request->request->get('target', 'private');
        if ($target === 'public') {
            if (!$this->isGranted('ROLE_SHARE_PUBLIC')) {
                $this->addFlash('danger', 'files.flash.public_not_allowed');

                return $this->redirectToFilesIndex($request);
            }
            $this->publicShareService->enablePublic($sharedFile, $sharedFile->getPublicExpiresAt());
        } else {
            $this->publicShareService->disablePublic($sharedFile);
        }
        $this->addFlash('success', 'files.flash.visibility_updated');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Attach a grantee to a private file when ROLE_SHARE_FRIENDS is granted.
     * @param Request $request HTTP request.
     * @param int $id Shared file identifier.
     * @return Response
     * @date 2026-04-27
     * @author Stephane H.
     */
    #[Route('/files/{id}/grant', name: 'files_grant', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function grant(Request $request, int $id): Response
    {
        if (!$this->isGranted('ROLE_SHARE_FRIENDS')) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_GRANT, (string) $request->request->get('_csrf_token', '')))) {
            $this->addFlash('danger', 'files.flash.csrf_invalid');

            return $this->redirectToFilesIndex($request);
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = (int) $user->getId();
        if (!$sharedFile instanceof SharedFile || $sharedFile->getOwnerUserId() !== $ownerId) {
            $this->addFlash('danger', 'files.flash.grant_invalid');

            return $this->redirectToFilesIndex($request);
        }

        $granteeId = (int) $request->request->get('grantee_user_id', 0);
        if ($granteeId <= 0 || $granteeId === $ownerId) {
            $this->addFlash('danger', 'files.flash.grant_invalid');

            return $this->redirectToFilesIndex($request);
        }
        $grantee = $this->userRepository->find($granteeId);
        if (!$grantee instanceof User) {
            $this->addFlash('danger', 'files.flash.grant_invalid');

            return $this->redirectToFilesIndex($request);
        }
        if ($this->shareGrantRepository->hasGrantForUser((int) $sharedFile->getId(), $granteeId)) {
            $this->addFlash('info', 'files.flash.grant_exists');

            return $this->redirectToFilesIndex($request);
        }

        $this->entityManager->persist(new ShareGrant((int) $sharedFile->getId(), $granteeId));
        $sharedFile->touchUpdatedAt();
        $this->entityManager->flush();
        $this->addFlash('success', 'files.flash.grant_added');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Revoke a grantee from a shared file (friends channel only; public channel is unaffected).
     * @param Request $request HTTP request.
     * @param int $id Shared file identifier.
     * @param int $granteeId Grantee user identifier.
     * @return Response
     * @date 2026-04-27
     * @author Stephane H.
     */
    #[Route('/files/{id}/revoke/{granteeId}', name: 'files_revoke', methods: ['POST'], requirements: ['id' => '\d+', 'granteeId' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function revoke(Request $request, int $id, int $granteeId): Response
    {
        if (!$this->isGranted('ROLE_SHARE_FRIENDS')) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_REVOKE, (string) $request->request->get('_csrf_token', '')))) {
            $this->addFlash('danger', 'files.flash.csrf_invalid');

            return $this->redirectToFilesIndex($request);
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        /** @var User $user */
        $user = $this->getUser();
        if (!$sharedFile instanceof SharedFile || $sharedFile->getOwnerUserId() !== (int) $user->getId()) {
            $this->addFlash('danger', 'files.flash.grant_invalid');

            return $this->redirectToFilesIndex($request);
        }

        $this->shareGrantRepository->deletePair((int) $sharedFile->getId(), $granteeId);
        $sharedFile->touchUpdatedAt();
        $this->entityManager->flush();
        $this->addFlash('success', 'files.flash.grant_removed');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Stream decrypted bytes for owners and approved grantees.
     * @param Request $request HTTP request.
     * @param int $id Shared file identifier.
     * @return Response
     * @date 2026-04-27
     * @author Stephane H.
     */
    #[Route('/files/download/{id}', name: 'files_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function download(Request $request, int $id): Response
    {
        $sharedFile = $this->sharedFileRepository->find($id);
        if (!$sharedFile instanceof SharedFile) {
            $this->addFlash('danger', 'files.flash.not_found');

            return $this->isGranted('ROLE_SHARE')
                ? $this->redirectToFilesIndex($request)
                : $this->redirectToRoute('app_home');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->canUserDownloadSharedFile($user, $sharedFile)) {
            throw $this->createAccessDeniedException();
        }

        $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
        $actor = (string) $user->getUserIdentifier();

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $sharedFile->getOriginalFileName()) ?: 'download.bin';

        $storagePath = $sharedFile->getStoragePath();
        if ($storagePath === '' || !is_readable($storagePath)) {
            $this->addFlash('danger', 'files.flash.download_failed');

            return $this->isGranted('ROLE_SHARE')
                ? $this->redirectToFilesIndex($request)
                : $this->redirectToRoute('app_home');
        }

        $response = new StreamedResponse(function () use ($storagePath): void {
            $this->fileEncryptionService->streamDecryptStorageToStdout($storagePath);
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$safeName.'"');
        $response->headers->set('Content-Length', (string) $sharedFile->getByteSize());

        $this->downloadAuditService->create($actor, $ip, $sharedFile->getPublicToken());

        return $response;
    }

    /**
     * @brief Stream decrypted bytes inline for browser preview (PDF, native video/audio, plain-text MIME allowlist with 20 MB cap).
     * @param Request $request Current HTTP request.
     * @param int $id Shared file identifier.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/files/preview/{id}', name: 'files_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function preview(Request $request, int $id): Response
    {
        $sharedFile = $this->sharedFileRepository->find($id);
        if (!$sharedFile instanceof SharedFile) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->canUserDownloadSharedFile($user, $sharedFile)) {
            throw $this->createAccessDeniedException();
        }

        $ext = strtolower($sharedFile->getFileExtension());
        $streamProfile = self::PREVIEW_STREAM_BY_EXTENSION[$ext] ?? null;
        if ($streamProfile === null) {
            throw $this->createNotFoundException();
        }

        $storagePath = $sharedFile->getStoragePath();
        if ($storagePath === '' || !is_readable($storagePath)) {
            $this->addFlash('danger', 'files.flash.download_failed');

            return $this->isGranted('ROLE_SHARE')
                ? $this->redirectToFilesIndex($request)
                : $this->redirectToRoute('app_home');
        }

        if (($streamProfile['kind'] ?? '') === 'text' && $sharedFile->getByteSize() > self::MAX_TEXT_PREVIEW_BYTES) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
        $actor = (string) $user->getUserIdentifier();
        $this->downloadAuditService->create($actor, $ip, $sharedFile->getPublicToken());

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $sharedFile->getOriginalFileName());
        if ($safeName === '' || $safeName === '_') {
            $safeName = 'preview.'.$ext;
        }

        $response = new StreamedResponse(function () use ($storagePath): void {
            $this->fileEncryptionService->streamDecryptStorageToStdout($storagePath);
        });
        $response->headers->set('Content-Type', $streamProfile['mime']);
        $response->headers->set('Content-Disposition', 'inline; filename="'.$safeName.'"');
        $response->headers->set('Content-Length', (string) $sharedFile->getByteSize());
        if (($streamProfile['kind'] ?? '') === 'text') {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Cache-Control', 'private, max-age=0, no-store');
        }

        return $response;
    }

    /**
     * @brief Download selected files as a single ZIP archive.
     * @param Request $request HTTP request containing query ids[].
     * @return Response
     * @date 2026-05-08
     * @author Stephane H.
     */
    #[Route('/files/download-selection-zip', name: 'files_download_selection_zip', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function downloadSelectionZip(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $effectiveOwnerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request, true);
        if (null === $effectiveOwnerId) {
            $this->addFlash('danger', 'files.flash.godview_subject_invalid');

            return $this->redirectToFilesIndex($request);
        }
        $raw = $request->query->all()['ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = [];
        foreach ($raw as $token) {
            $id = (int) $token;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        $rawFolderIds = $request->query->all()['folder_ids'] ?? [];
        if (!is_array($rawFolderIds)) {
            $rawFolderIds = [];
        }
        $folderIds = [];
        foreach ($rawFolderIds as $token) {
            $id = (int) $token;
            if ($id > 0) {
                $folderIds[$id] = $id;
            }
        }
        $folderIds = array_values($folderIds);
        if ($this->isGranted('ROLE_ADMIN') && $folderIds !== []) {
            $ownerCandidates = [];
            foreach ($folderIds as $folderId) {
                $folderCandidate = $this->folderRepository->find($folderId);
                if ($folderCandidate instanceof Folder) {
                    $ownerCandidates[(int) $folderCandidate->getOwnerUserId()] = (int) $folderCandidate->getOwnerUserId();
                }
            }
            if (count($ownerCandidates) === 1) {
                $effectiveOwnerId = (int) array_values($ownerCandidates)[0];
            }
        }
        if ($ids === [] && $folderIds === []) {
            $this->addFlash('danger', 'files.flash.bulk_no_ids');

            return $this->redirectToFilesIndex($request);
        }
        $zip = new \ZipArchive();
        $zipName = 'files-selection-'.(new \DateTimeImmutable())->format('Ymd-Hi').'.zip';
        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage_'.$zipName;
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $added = 0;
        $selectedFolderRoots = [];
        $candidateFileContexts = [];
        foreach ($ids as $id) {
            $candidateFileContexts[$id] = [
                'fileId' => $id,
                'selectedFolderId' => null,
            ];
        }
        $acceptedFolderIds = [];
        if ($this->isGranted('ROLE_SHARE_SEND')) {
            foreach ($folderIds as $folderId) {
                $folder = $this->folderRepository->find($folderId);
                if (!$folder instanceof Folder || (int) $folder->getOwnerUserId() !== $effectiveOwnerId) {
                    continue;
                }
                $acceptedFolderIds[] = (int) $folder->getId();
                $selectedFolderRoots[(int) $folder->getId()] = $folder;
                foreach ($this->folderTreeService->collectSubtreeFolders($effectiveOwnerId, $folder) as $subFolder) {
                    $rows = $this->sharedFileRepository->findBy([
                        'ownerUserId' => $effectiveOwnerId,
                        'folder' => $subFolder,
                    ]);
                    foreach ($rows as $row) {
                        if ($row instanceof SharedFile && $row->getId() !== null) {
                            $fileId = (int) $row->getId();
                            if (!isset($candidateFileContexts[$fileId])) {
                                $candidateFileContexts[$fileId] = [
                                    'fileId' => $fileId,
                                    'selectedFolderId' => (int) $folder->getId(),
                                ];
                                continue;
                            }
                            if ($candidateFileContexts[$fileId]['selectedFolderId'] === null) {
                                $candidateFileContexts[$fileId]['selectedFolderId'] = (int) $folder->getId();
                            }
                        }
                    }
                }
            }
        }
        $usedEntryNames = [];
        foreach (array_values($candidateFileContexts) as $fileContext) {
            $id = (int) ($fileContext['fileId'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $sharedFile = $this->sharedFileRepository->find($id);
            if (!$sharedFile instanceof SharedFile) {
                continue;
            }
            if (!$this->canUserDownloadSharedFile($user, $sharedFile)) {
                continue;
            }
            try {
                $plain = $this->fileEncryptionService->decryptFromStorage($sharedFile->getStoragePath());
            } catch (\RuntimeException) {
                continue;
            }
            $rawEntryName = (string) $sharedFile->getOriginalFileName();
            $selectedFolderId = (int) ($fileContext['selectedFolderId'] ?? 0);
            if ($selectedFolderId > 0 && isset($selectedFolderRoots[$selectedFolderId])) {
                $selectedRoot = $selectedFolderRoots[$selectedFolderId];
                $fileFolder = $sharedFile->getFolder();
                if ($fileFolder instanceof Folder) {
                    $relativePrefix = $this->folderZipService->buildRelativeFolderPathFromRoot($selectedRoot, $fileFolder);
                    $rawEntryName = ltrim($relativePrefix.'/'.$sharedFile->getOriginalFileName(), '/');
                }
            }
            $entryName = $this->buildUniqueZipEntryName($rawEntryName, $id, $usedEntryNames);
            $zip->addFromString($entryName, $plain);
            $added++;
        }
        $zip->close();
        if ($added < 1) {
            $this->addFlash('danger', 'files.flash.not_found');

            return $this->redirectToFilesIndex($request);
        }

        return $this->file($zipPath, $zipName);
    }

    /**
     * @brief Build one unique sanitized zip entry path with deterministic suffix fallback.
     * @param string $rawEntryPath Raw candidate entry path.
     * @param int $fallbackFileId Fallback file identifier for sanitizer fallback names.
     * @param array<string, bool> $usedEntryNames Already-used sanitized entry paths.
     * @return string
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function buildUniqueZipEntryName(string $rawEntryPath, int $fallbackFileId, array &$usedEntryNames): string
    {
        $baseEntryName = ZipEntryNameSanitizer::sanitizeEntryPath($rawEntryPath, $fallbackFileId);
        $candidateEntryName = $baseEntryName;
        $suffix = 1;
        while (isset($usedEntryNames[$candidateEntryName])) {
            $suffix++;
            $candidateWithSuffix = $this->appendZipSuffixToEntryName($baseEntryName, $suffix);
            $candidateEntryName = ZipEntryNameSanitizer::sanitizeEntryPath($candidateWithSuffix, $fallbackFileId);
        }
        $usedEntryNames[$candidateEntryName] = true;

        return $candidateEntryName;
    }

    /**
     * @brief Append deterministic numeric suffix to zip entry basename while preserving directory segments.
     * @param string $entryPath Current entry path.
     * @param int $suffix Numeric suffix (>= 2).
     * @return string
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function appendZipSuffixToEntryName(string $entryPath, int $suffix): string
    {
        $lastSlash = strrpos($entryPath, '/');
        $directoryPrefix = $lastSlash === false ? '' : substr($entryPath, 0, $lastSlash + 1);
        $basename = $lastSlash === false ? $entryPath : substr($entryPath, $lastSlash + 1);
        $dot = strrpos($basename, '.');
        if ($dot === false) {
            return $directoryPrefix.$basename.'_'.$suffix;
        }

        return $directoryPrefix.substr($basename, 0, $dot).'_'.$suffix.substr($basename, $dot);
    }

    /**
     * @brief Check whether a user can download a given shared file.
     * @param User $user Current authenticated user.
     * @param SharedFile $sharedFile Shared file target.
     * @return bool
     * @date 2026-04-30
     * @author Stephane H.
     */
    private function canUserDownloadSharedFile(User $user, SharedFile $sharedFile): bool
    {
        $requesterId = (int) $user->getId();
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if ($sharedFile->getOwnerUserId() === $requesterId && $this->isGranted('ROLE_SHARE_SEND')) {
            return true;
        }
        $hasGrant = $this->shareGrantRepository->hasGrantForUser((int) $sharedFile->getId(), $requesterId);

        return $this->shareAuthorizationService->canAccessPrivateByUser($sharedFile, $requesterId, $hasGrant);
    }

    /**
     * @brief Create a folder in current owner scope.
     * @param Request $request HTTP request.
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route('/files/folders/create', name: 'files_folder_create', methods: ['POST'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function createFolder(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FOLDER_CREATE, (string) $request->request->get('_csrf_token', '')))) {
            $this->addFlash('danger', 'files.flash.csrf_invalid');

            return $this->redirectToFilesIndex($request);
        }
        /** @var User $user */
        $user = $this->getUser();
        $resolved = $this->resolveUploadOrFolderTargetOwnerId($request, $user);
        if (isset($resolved['error'])) {
            $this->addFlash('danger', $resolved['error']);

            return $this->redirectToFilesIndex($request);
        }
        $ownerId = $resolved['ownerId'];
        $name = trim((string) $request->request->get('folder_name', ''));
        if ($name === '') {
            $this->addFlash('danger', 'files.folder.flash.name_required');

            return $this->redirectToFilesIndex($request);
        }
        $parentId = (int) $request->request->get('parent_folder_id', 0);
        $parent = $this->folderTreeService->resolveCurrentFolder($ownerId, $parentId > 0 ? $parentId : null);
        if ($parentId > 0 && !$parent instanceof Folder) {
            $this->addFlash('danger', 'files.folder.flash.parent_invalid');

            return $this->redirectToFilesIndex($request);
        }
        $normalized = Folder::normalizeName($name);
        $existing = $this->folderRepository->findOneByOwnerParentAndNormalizedName($ownerId, $parent, $normalized);
        if ($existing instanceof Folder) {
            $this->addFlash('warning', 'files.folder.flash.already_exists');

            return $this->redirectToFilesIndex($request);
        }
        if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerId, $parent, $normalized, null) instanceof SharedFile) {
            $this->addFlash('warning', 'files.folder.flash.name_conflict_with_file');

            return $this->redirectToFilesIndex($request);
        }
        $folder = new Folder($ownerId, $name, $parent);
        try {
            $this->entityManager->persist($folder);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('warning', 'files.folder.flash.already_exists');

            return $this->redirectToFilesIndex($request);
        }
        $this->addFlash('success', 'files.folder.flash.created');

        return $this->redirectToFilesIndexMerged($request, [
            'folder' => $parent?->getId(),
        ]);
    }

    /**
     * @brief Delete a folder recursively with all descendants and files (uses deleteOwnedFolderSubtree).
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/delete', name: 'files_folder_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function deleteFolder(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FOLDER_DELETE, (string) $request->request->get('_csrf_token', '')))) {
            $this->addFlash('danger', 'files.flash.csrf_invalid');

            return $this->redirectToFilesIndex($request);
        }
        /** @var User $user */
        $user = $this->getUser();
        $actorId = (int) $user->getId();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            $this->addFlash('danger', 'files.flash.godview_subject_invalid');

            return $this->redirectToFilesIndex($request);
        }
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            $this->addFlash('danger', 'files.flash.not_found');

            return $this->redirectToFilesIndex($request);
        }
        $parentId = $folder->getParent()?->getId();
        $this->deleteOwnedFolderSubtree($ownerId, $folder);
        $this->addFlash('success', 'files.folder.flash.deleted_recursive');

        return $this->redirectToFilesIndexMerged($request, [
            'folder' => $parentId,
        ]);
    }

    /**
     * @brief Apply recursive public sharing to one folder subtree.
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @return Response
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/share/public', name: 'files_folder_share_public', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    #[IsGranted('ROLE_SHARE_PUBLIC')]
    public function folderSharePublic(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FOLDER_SHARE_PUBLIC, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }
        $actorId = (int) $user->getId();
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            return $this->shareJsonOrRedirect($request, 'files.flash.not_found', 404);
        }
        $payload = $this->extractPublicSharePayload($request);
        $folder->setPublicShareEnabled($payload['enabled']);
        $folder->setPublicShareExpiresAt($payload['enabled'] ? $payload['expires_at'] : null);
        $this->entityManager->flush();
        $count = $this->folderShareService->applyPublicRecursive($ownerId, $folder, $payload['enabled'], $payload['expires_at']);
        $passwordPlain = null;
        if ($payload['enabled']) {
            $this->folderPublicTokenService->ensurePublicFolderToken($folder);
            $passwordPlain = $this->syncPublicFolderPassword($folder, $payload);
        } else {
            $this->folderPublicTokenService->revokePublicFolderToken($folder);
            $this->publicShareResourcePasswordService->clearFolder($folder);
        }
        $this->entityManager->flush();
        if ($this->expectsJson($request)) {
            $out = ['status' => 'ok', 'count' => $count];
            if ($passwordPlain !== null) {
                $out['public_password_plain'] = $passwordPlain;
            }

            return new JsonResponse($out);
        }
        $this->addFlash('success', 'files.flash.share_applied');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Toggle the public password gate for one owned folder without applying full public share submit.
     * @param Request $request HTTP request (form-encoded).
     * @param int $id Folder identifier.
     * @return JsonResponse
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/share/public/password-toggle', name: 'files_folder_share_public_password_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    #[IsGranted('ROLE_SHARE_PUBLIC')]
    public function folderSharePublicPasswordToggle(Request $request, int $id): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FOLDER_SHARE_PUBLIC, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 400);
        }
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        $passwordEnabled = filter_var($request->request->get('public_password_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        $passwordPlain = $this->publicShareResourcePasswordService->applyToFolder($folder, $passwordEnabled);
        $this->entityManager->flush();

        $actorId = (int) $user->getId();

        $out = [
            'status' => 'ok',
            'password_enabled' => $passwordEnabled,
        ];
        if ($passwordEnabled && $passwordPlain !== null && $passwordPlain !== '') {
            $out['public_password_plain'] = $passwordPlain;
        }

        return new JsonResponse($out);
    }

    /**
     * @brief Return or materialize a public landing URL for one folder subtree (sync folder policy to files when needed).
     * @param Request $request HTTP request (CSRF body field).
     * @param int $id Folder identifier.
     * @return JsonResponse
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/public-landing-url', name: 'files_folder_public_landing_url', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    #[IsGranted('ROLE_SHARE_PUBLIC')]
    public function folderPublicLandingUrl(Request $request, int $id): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FOLDER_SHARE_PUBLIC, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 400);
        }
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $actorId = (int) $user->getId();
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        $url = $this->resolvePublicLandingUrlForOwnerFolderSubtree($ownerId, $folder);
        if ($url !== null) {
            return new JsonResponse(['status' => 'ok', 'url' => $url]);
        }

        if (!$folder->isPublicShareEnabled()) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.folder.public_link.resolve_failed'], 400);
        }

        $expiresAt = $folder->getPublicShareExpiresAt();
        $count = $this->folderShareService->applyPublicRecursive($ownerId, $folder, true, $expiresAt);

        $url = $this->resolvePublicLandingUrlForOwnerFolderSubtree($ownerId, $folder);
        if ($url === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.folder.public_link.resolve_failed'], 400);
        }

        return new JsonResponse(['status' => 'ok', 'url' => $url]);
    }

    /**
     * @brief Apply recursive friends sharing to one folder subtree.
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @return Response
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/share/friends', name: 'files_folder_share_friends', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    #[IsGranted('ROLE_SHARE_FRIENDS')]
    public function folderShareFriends(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FOLDER_SHARE_FRIENDS, (string) $request->request->get('_csrf_token', '')))) {
            return $this->shareJsonOrRedirect($request, 'files.flash.csrf_invalid', 400);
        }
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request);
        if (null === $ownerId) {
            return $this->shareJsonOrRedirect($request, 'files.flash.godview_subject_invalid', 400);
        }
        $actorId = (int) $user->getId();
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            return $this->shareJsonOrRedirect($request, 'files.flash.not_found', 404);
        }
        $payload = $this->extractFriendsSharePayload($request);
        $folderUserIds = [];
        foreach ($payload['grantees'] as $grantee) {
            $candidate = (int) ($grantee['user_id'] ?? 0);
            if ($candidate > 0 && $candidate !== $ownerId) {
                $folderUserIds[$candidate] = $candidate;
            }
        }
        $folder->setFriendsShareUserIds(array_values($folderUserIds));
        $this->entityManager->flush();
        $count = $this->folderShareService->applyFriendsRecursive($ownerId, $folder, $payload['grantees'], $payload['replace_existing']);
        if ($this->expectsJson($request)) {
            return new JsonResponse(['status' => 'ok', 'count' => $count]);
        }
        $this->addFlash('success', 'files.flash.share_applied');

        return $this->redirectToFilesIndex($request);
    }

    /**
     * @brief Build label map for grantee user ids with pseudonym fallback.
     * @param array<int, int> $userIds Grantee identifiers.
     * @param TranslatorInterface $translator Translator service.
     * @return array<int, string>
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function buildFriendsRecipientLabelMap(array $userIds, TranslatorInterface $translator): array
    {
        $labelsById = [];
        foreach ($this->userRepository->findByIdsOrdered($userIds) as $granteeUser) {
            $granteeId = (int) $granteeUser->getId();
            $labelsById[$granteeId] = $granteeUser->getPseudonym() !== ''
                ? $granteeUser->getPseudonym()
                : $translator->trans('files.upload.grantee_label_fallback', [], 'messages');
        }

        return $labelsById;
    }

    /**
     * @brief Friends recipients from folder JSON only (empty subtree), same keys as file share state rows.
     * @param Folder $folder Owned folder.
     * @param int $ownerId Owner user identifier.
     * @param TranslatorInterface $translator Translator service.
     * @return array<int, array<string, mixed>>
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function buildFolderFriendsRowsFromEntityIntentOnly(Folder $folder, int $ownerId, TranslatorInterface $translator): array
    {
        $friends = [];
        $folderUserIds = $folder->getFriendsShareUserIds();
        $labelsById = $this->buildFriendsRecipientLabelMap($folderUserIds, $translator);
        foreach ($folderUserIds as $granteeId) {
            $intId = (int) $granteeId;
            if ($intId <= 0 || $intId === $ownerId) {
                continue;
            }
            $friends[] = [
                'user_id' => $intId,
                'label' => $labelsById[$intId] ?? (string) $intId,
                'expires_at' => null,
                'expired' => false,
                'expiration_mixed' => false,
            ];
        }

        return $friends;
    }

    /**
     * @brief Aggregate friends grants across all files in a folder subtree for modal prefill; rows with only expired grants are omitted.
     * @param Folder $folder Owned folder root.
     * @param int $ownerId Owner user identifier.
     * @param TranslatorInterface $translator Translator service.
     * @return array<int, array<string, mixed>>
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function buildFolderSubtreeFriendsRows(Folder $folder, int $ownerId, TranslatorInterface $translator): array
    {
        $subFolders = $this->folderTreeService->collectSubtreeFolders($ownerId, $folder);
        $fileIds = [];
        foreach ($subFolders as $subFolder) {
            $files = $this->sharedFileRepository->findBy(['ownerUserId' => $ownerId, 'folder' => $subFolder]);
            foreach ($files as $sf) {
                if ($sf instanceof SharedFile && $sf->getId() !== null) {
                    $fileIds[] = (int) $sf->getId();
                }
            }
        }
        $fileIds = array_values(array_unique($fileIds));

        if ($fileIds === []) {
            return $this->buildFolderFriendsRowsFromEntityIntentOnly($folder, $ownerId, $translator);
        }

        $allGrants = $this->shareGrantRepository->findAllBySharedFileIds($fileIds);
        $byGrantee = [];
        foreach ($allGrants as $grant) {
            if (!$grant instanceof ShareGrant) {
                continue;
            }
            $uid = $grant->getGranteeUserId();
            if ($uid <= 0 || $uid === $ownerId) {
                continue;
            }
            if (!isset($byGrantee[$uid])) {
                $byGrantee[$uid] = [];
            }
            $byGrantee[$uid][] = $grant;
        }

        $labelMap = $this->buildFriendsRecipientLabelMap(array_keys($byGrantee), $translator);

        $rows = [];
        foreach ($byGrantee as $uid => $grants) {
            $activeGrants = array_values(array_filter(
                $grants,
                fn (ShareGrant $g): bool => $this->shareGrantRepository->isFriendsGrantActiveAtDatabaseNow(
                    (int) $g->getSharedFileId(),
                    (int) $g->getGranteeUserId()
                )
            ));
            $expired = $activeGrants === [] && $grants !== [];
            $expiresAtStr = null;
            $expirationMixed = false;

            if (!$expired && $activeGrants !== []) {
                $nullCount = 0;
                $distinctDates = [];
                foreach ($activeGrants as $g) {
                    $expAt = $g->getExpiresAt();
                    if ($expAt === null) {
                        ++$nullCount;
                    } else {
                        $distinctDates[$expAt->format('Y-m-d\TH:i')] = true;
                    }
                }
                $nDistinct = \count($distinctDates);
                $nActive = \count($activeGrants);

                if ($nullCount === $nActive) {
                    $expiresAtStr = null;
                } elseif ($nullCount === 0 && $nDistinct === 1) {
                    $expiresAtStr = array_key_first($distinctDates);
                } else {
                    $expirationMixed = true;
                }
            }

            $rows[] = [
                'user_id' => $uid,
                'label' => $labelMap[$uid] ?? (string) $uid,
                'expires_at' => $expiresAtStr,
                'expired' => $expired,
                'expiration_mixed' => $expirationMixed,
            ];
        }

        $rows = array_values(array_filter($rows, static function (array $r): bool {
            return empty($r['expired']);
        }));

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return $rows;
    }

    /**
     * @brief Read folder-level share state including persisted friends recipients for modal prefill (public.enabled/active reflect effective policy and expiry).
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @param TranslatorInterface $translator Translator for recipient fallback labels.
     * @return JsonResponse
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/share/state', name: 'files_folder_share_state', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function folderShareState(Request $request, int $id, TranslatorInterface $translator): JsonResponse
    {
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request, true);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        /** @var User $user */
        $user = $this->getUser();
        $isAdminActor = $this->isGranted('ROLE_ADMIN');
        $isGodviewProxy = $isAdminActor && (int) $user->getId() !== $ownerId;
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        $friends = $this->buildFolderSubtreeFriendsRows($folder, $ownerId, $translator);

        $publicEffective = $folder->isPublicShareEffectivelyActive();
        $folderPwdPlain = null;
        $passwordCopyAvailable = !$isGodviewProxy && $folder->isPublicPasswordEnabled() && $publicEffective && $folder->getPublicFolderToken() !== null;
        if ($passwordCopyAvailable) {
            $folderPwdPlain = $this->publicShareResourcePasswordService->decryptPlainForOwnerFolder($folder);
        }

        return new JsonResponse([
            'status' => 'ok',
            'id' => (int) $folder->getId(),
            'public' => [
                'enabled' => $publicEffective,
                'active' => $publicEffective,
                'expires_at' => $publicEffective
                    ? $folder->getPublicShareExpiresAt()?->format(\DateTimeInterface::ATOM)
                    : null,
                'token' => ($publicEffective && !$isGodviewProxy) ? $folder->getPublicFolderToken() : null,
                'password_enabled' => $folder->isPublicPasswordEnabled() && $publicEffective,
                'password_plain' => $folderPwdPlain,
                'password_copy_available' => $passwordCopyAvailable,
            ],
            'friends' => $friends,
        ]);
    }

    /**
     * @brief Stream ZIP download for a folder subtree.
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @return Response
     * @date 2026-05-07
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/download-zip', name: 'files_folder_download_zip', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function downloadFolderZip(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = (int) $user->getId();
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            $this->addFlash('danger', 'files.flash.not_found');

            return $this->redirectToFilesIndex($request);
        }
        $zip = $this->folderZipService->buildFolderZip($ownerId, $folder);

        return $this->file($zip['zip_path'], $zip['zip_name']);
    }

    /**
     * @brief Read owner-side recursive properties for one folder subtree.
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @return JsonResponse
     * @date 2026-05-05
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/properties', name: 'files_folder_properties', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function folderProperties(Request $request, int $id): JsonResponse
    {
        $ownerId = $this->tryResolveEffectiveOwnerIdForAdminSubject($request, true);
        if (null === $ownerId) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        $aggregates = $this->folderPropertiesService->buildRecursiveProperties($ownerId, $folder);
        $shareState = $this->folderPropertiesService->buildRecursiveShareState($ownerId, $folder);

        return new JsonResponse([
            'status' => 'ok',
            'id' => (int) $folder->getId(),
            'name' => $folder->getName(),
            'created_at' => $folder->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $folder->getUpdatedAt()->format(DATE_ATOM),
            'total_bytes' => $aggregates['totalBytes'],
            'total_bytes_formatted' => $this->binaryByteFormatter->format($aggregates['totalBytes']),
            'total_files' => $aggregates['totalFiles'],
            'total_subfolders' => $aggregates['totalSubfolders'],
            'public_active' => $shareState['publicActive'],
            'friends_active' => $shareState['friendsActive'],
            'files_in_subtree' => $shareState['filesInSubtree'],
        ]);
    }

    /**
     * @brief Read grantee-side recursive properties for a shared folder subtree.
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @return JsonResponse
     * @date 2026-05-08
     * @author Stephane H.
     */
    #[Route('/files/shared-folders/{id}/properties', name: 'files_shared_folder_properties', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function sharedFolderProperties(Request $request, int $id): JsonResponse
    {
        $effectiveGranteeId = $this->tryResolveEffectiveGranteeIdForAdminSubject($request, true);
        if ($effectiveGranteeId === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.godview_subject_invalid'], 400);
        }
        $ctx = $this->resolveSharedFolderAccess($id, $effectiveGranteeId);
        if ($ctx === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }
        $totalBytes = 0;
        foreach ($ctx['files'] as $file) {
            $totalBytes += (int) $file->getByteSize();
        }
        $ownerUser = $this->userRepository->find($ctx['folder']->getOwnerUserId());
        $sharedByLabel = $ownerUser instanceof User
            ? ($ownerUser->getPseudonym() !== '' ? $ownerUser->getPseudonym() : $ownerUser->getEmail())
            : (string) $ctx['folder']->getOwnerUserId();
        return new JsonResponse([
            'status' => 'ok',
            'id' => (int) $ctx['folder']->getId(),
            'name' => $ctx['folder']->getName(),
            'shared_by' => $sharedByLabel,
            'created_at' => $ctx['folder']->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $ctx['folder']->getUpdatedAt()->format(DATE_ATOM),
            'total_bytes' => $totalBytes,
            'total_bytes_formatted' => $this->binaryByteFormatter->format($totalBytes),
            'total_files' => count($ctx['files']),
            'total_subfolders' => max(0, count($ctx['folders']) - 1),
        ]);
    }

    /**
     * @brief Download a ZIP archive for files accessible inside a shared folder subtree.
     * @param Request $request HTTP request.
     * @param int $id Folder identifier.
     * @return Response
     * @date 2026-05-08
     * @author Stephane H.
     */
    #[Route('/files/shared-folders/{id}/download-zip', name: 'files_shared_folder_download_zip', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function sharedFolderDownloadZip(Request $request, int $id): Response
    {
        $effectiveGranteeId = $this->tryResolveEffectiveGranteeIdForAdminSubject($request, true);
        if ($effectiveGranteeId === null) {
            throw $this->createNotFoundException();
        }
        $ctx = $this->resolveSharedFolderAccess($id, $effectiveGranteeId);
        if ($ctx === null) {
            throw $this->createNotFoundException();
        }
        $zip = $this->folderZipService->buildFolderZipFromFiles($ctx['folder'], $ctx['files']);

        return $this->file($zip['zip_path'], $zip['zip_name']);
    }

    /**
     * @brief Redirect back to filtered file listing preserving toolbar query or POST retain fields.
     * @param Request $request Incoming HTTP request.
     * @return Response
     * @date 2026-04-27
     * @author Stephane H.
     */
    /**
     * @brief Choose files_index vs admin_files_index from filtered listing parameters.
     * @param array<string, mixed> $listingParams Filtered query parameters.
     * @return string
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function listingRedirectRouteName(array $listingParams): string
    {
        if (($listingParams['admin_context'] ?? '') === '1' && $this->isGranted('ROLE_ADMIN')) {
            return 'admin_files_index';
        }

        return 'files_index';
    }

    /**
     * @brief Redirect to file listing with optional query keys merged and re-filtered.
     * @param Request $request HTTP request.
     * @param array<string, mixed> $merge Route parameters to merge (e.g. folder id).
     * @return Response
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function redirectToFilesIndexMerged(Request $request, array $merge): Response
    {
        $params = $this->filterListingRouteParams(array_merge($this->extractListingRouteParams($request), $merge));

        return $this->redirectToRoute($this->listingRedirectRouteName($params), $params);
    }

    private function redirectToFilesIndex(Request $request): Response
    {
        $params = $this->extractListingRouteParams($request);

        return $this->redirectToRoute($this->listingRedirectRouteName($params), $params);
    }

    /**
     * @brief Build filtered route params from GET query or POST listing retain controls.
     * @param Request $request Incoming HTTP request.
     * @return array<string, mixed>
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function extractListingRouteParams(Request $request): array
    {
        if ($request->request->has('_retain_sort')) {
            return $this->listingRouteParamsFromRetainBag($request->request);
        }

        return $this->filterListingRouteParams($request->query->all());
    }

    /**
     * @brief Normalize listing params from hidden POST retain fields, preserving neutral sort by omitting empty sort/direction.
     * @param ParameterBag $bag Request body bag.
     * @return array<string, mixed>
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function listingRouteParamsFromRetainBag(ParameterBag $bag): array
    {
        $params = [];
        $q = trim((string) $bag->get('_retain_q', ''));
        if ($q !== '') {
            $params['q'] = $q;
        }
        $sort = strtolower(trim((string) $bag->get('_retain_sort', '')));
        if ($sort !== '') {
            $params['sort'] = $sort;
        }
        $dir = strtolower(trim((string) $bag->get('_retain_dir', '')));
        if ($dir !== '') {
            $params['dir'] = $dir;
        }
        $filterPublic = strtolower(trim((string) $bag->get('_retain_filter_public', '')));
        if ($filterPublic !== '') {
            $params['filter_public'] = $filterPublic;
        }
        $view = strtolower(trim((string) $bag->get('_retain_view', '')));
        if ($view !== '') {
            $params['view'] = $view;
        }
        $folder = (int) $bag->get('_retain_folder', 0);
        if ($folder > 0) {
            $params['folder'] = $folder;
        }
        $listingScopeRetain = strtolower(trim((string) $bag->get('_retain_listing_scope', '')));
        if (\in_array($listingScopeRetain, ['owned', 'shared'], true)) {
            $params['listing_scope'] = $listingScopeRetain;
        }
        $retainAdminContext = (string) $bag->get('_retain_admin_context', '');
        if ($retainAdminContext === '1') {
            $params['admin_context'] = '1';
        }
        $retainAdminViewScope = strtolower(trim((string) $bag->get('_retain_admin_view_scope', '')));
        if (\in_array($retainAdminViewScope, ['owner', 'all'], true)) {
            $params['admin_view_scope'] = $retainAdminViewScope;
        }
        $retainOwner = (int) $bag->get('_retain_owner', 0);
        if ($retainOwner > 0) {
            $params['owner'] = (string) $retainOwner;
        }
        $retainOwnerQuery = trim((string) $bag->get('_retain_owner_query', ''));
        if ($retainOwnerQuery !== '') {
            $params['owner_query'] = $retainOwnerQuery;
        }
        $extRaw = $bag->get('_retain_ext');
        $extList = [];
        if (is_array($extRaw)) {
            $extList = $extRaw;
        } elseif (is_string($extRaw) && $extRaw !== '') {
            $extList = [$extRaw];
        }
        foreach ($this->normalizeExtensionTokens($extList) as $ext) {
            $params['ext'][] = $ext;
        }

        $fhg = strtolower(trim((string) $bag->get('_retain_filter_has_grant', '')));
        if ($fhg !== '') {
            $params['filter_has_grant'] = $fhg;
        }
        $allRetain = $bag->all();
        $grantRaw = $allRetain['_retain_grantee'] ?? [];
        $grantList = [];
        if (is_array($grantRaw)) {
            $grantList = $grantRaw;
        } elseif (is_string($grantRaw) && $grantRaw !== '') {
            $grantList = [$grantRaw];
        }
        foreach ($this->normalizeGranteeIds($grantList) as $gid) {
            $params['grantee'][] = $gid;
        }
        foreach (['uploaded_after', 'uploaded_before', 'updated_after', 'updated_before', 'expires_after', 'expires_before'] as $dk) {
            $rk = '_retain_'.$dk;
            $raw = trim((string) $bag->get($rk, ''));
            if ($raw !== '') {
                $params[$dk] = $raw;
            }
        }

        $retainViewScope = strtolower(trim((string) $bag->get('_retain_view_scope', '')));
        if (\in_array($retainViewScope, ['me', 'user', 'all'], true)) {
            $params['view_scope'] = $retainViewScope;
        }
        $retainSubject = (int) $bag->get('_retain_subject_user', 0);
        if ($retainSubject > 0) {
            $params['subject_user'] = $retainSubject;
        }
        $retainUsersPage = (int) $bag->get('_retain_users_page', 0);
        if ($retainUsersPage > 0) {
            $params['users_page'] = $retainUsersPage;
        }
        $retainUsersPageSize = (int) $bag->get('_retain_users_page_size', 0);
        if ($retainUsersPageSize > 0) {
            $params['users_page_size'] = $retainUsersPageSize;
        }
        $retainUsersSort = strtolower(trim((string) $bag->get('_retain_users_sort', '')));
        if ($retainUsersSort !== '') {
            $params['users_sort'] = $retainUsersSort;
        }
        $retainUsersDir = strtolower(trim((string) $bag->get('_retain_users_dir', '')));
        if ($retainUsersDir !== '') {
            $params['users_dir'] = $retainUsersDir;
        }
        $retainPane = trim((string) $bag->get('_retain_pane', ''));
        if ($retainPane !== '') {
            $params['pane'] = $retainPane;
        }
        $retainSharedFolder = (int) $bag->get('_retain_shared_folder', 0);
        if ($retainSharedFolder > 0) {
            $params['shared_folder'] = $retainSharedFolder;
        }

        return $this->filterListingRouteParams($params);
    }

    /**
     * @brief Allow-list keys echoed into files_index redirects.
     * @param array<string, mixed> $query Raw query map.
     * @return array<string, mixed>
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function filterListingRouteParams(array $query): array
    {
        $out = [];
        foreach (['q', 'sort', 'dir', 'filter_public', 'view', 'folder', 'shared_folder', 'owner', 'owner_query'] as $key) {
            if (!\array_key_exists($key, $query)) {
                continue;
            }
            $value = $query[$key];
            if (is_array($value)) {
                continue;
            }
            if ((string) $value !== '') {
                $out[$key] = $value;
            }
        }

        $listingScope = strtolower(trim((string) ($query['listing_scope'] ?? '')));
        if (\in_array($listingScope, ['owned', 'shared'], true)) {
            $out['listing_scope'] = $listingScope;
        }
        $adminContext = (string) ($query['admin_context'] ?? '');
        if ($adminContext === '1' && $this->isGranted('ROLE_ADMIN')) {
            $out['admin_context'] = '1';
        }
        $adminViewScope = strtolower(trim((string) ($query['admin_view_scope'] ?? '')));
        if ($adminContext === '1' && $this->isGranted('ROLE_ADMIN') && \in_array($adminViewScope, ['owner', 'all'], true)) {
            $out['admin_view_scope'] = $adminViewScope;
        }

        $viewScope = strtolower(trim((string) ($query['view_scope'] ?? '')));
        if (\in_array($viewScope, ['me', 'user', 'all'], true)) {
            $out['view_scope'] = $viewScope;
        }
        $subjectUser = (int) ($query['subject_user'] ?? 0);
        if ($subjectUser > 0) {
            $out['subject_user'] = $subjectUser;
        }
        $usersPage = (int) ($query['users_page'] ?? 0);
        if ($usersPage > 0) {
            $out['users_page'] = $usersPage;
        }
        $usersPageSize = (int) ($query['users_page_size'] ?? 0);
        if (\in_array($usersPageSize, UserFilesPaneBuilderService::allowedUsersPageSizes(), true)) {
            $out['users_page_size'] = $usersPageSize;
        }
        $usersSort = strtolower(trim((string) ($query['users_sort'] ?? '')));
        if (\in_array($usersSort, ['pseudo', 'id'], true)) {
            $out['users_sort'] = $usersSort;
        }
        $usersDir = strtolower(trim((string) ($query['users_dir'] ?? '')));
        if (\in_array($usersDir, ['asc', 'desc'], true)) {
            $out['users_dir'] = $usersDir;
        }
        $paneQueryFilter = trim((string) ($query['pane'] ?? ''));
        if ($paneQueryFilter !== '') {
            $out['pane'] = $paneQueryFilter;
        }

        $extRaw = $query['ext'] ?? [];
        $extList = [];
        if (is_array($extRaw)) {
            $extList = $extRaw;
        } elseif (is_string($extRaw) && $extRaw !== '') {
            $extList = [$extRaw];
        }
        foreach ($this->normalizeExtensionTokens($extList) as $ext) {
            $out['ext'][] = $ext;
        }

        foreach (['uploaded_after', 'uploaded_before', 'updated_after', 'updated_before', 'expires_after', 'expires_before'] as $dk) {
            if (!\array_key_exists($dk, $query)) {
                continue;
            }
            $value = $query[$dk];
            if (is_array($value)) {
                continue;
            }
            $raw = trim((string) $value);
            if ($raw !== '') {
                $out[$dk] = $raw;
            }
        }

        $fhg = strtolower(trim((string) ($query['filter_has_grant'] ?? '')));
        if (\in_array($fhg, ['yes', 'no'], true)) {
            $out['filter_has_grant'] = $fhg;
        }

        $grantRaw = $query['grantee'] ?? [];
        if (!is_array($grantRaw)) {
            $grantRaw = $grantRaw !== null && $grantRaw !== '' ? [$grantRaw] : [];
        }
        foreach ($this->normalizeGranteeIds($grantRaw) as $gid) {
            $out['grantee'][] = $gid;
        }

        foreach ($query as $ufOrSfKey => $ufOrSfValue) {
            if (!\is_string($ufOrSfKey) || \is_array($ufOrSfValue)) {
                continue;
            }
            if (1 !== preg_match('/^(uf|sf)_[1-9]\\d*$/', $ufOrSfKey)) {
                continue;
            }
            $ufOrSfInt = (int) $ufOrSfValue;
            if ($ufOrSfInt > 0) {
                $out[$ufOrSfKey] = $ufOrSfInt;
            }
        }

        return $out;
    }

    /**
     * @brief Normalize raw extension tokens to safe lower-case fragments.
     * @param array<int|string, mixed> $tokens Raw tokens.
     * @return array<int, string>
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function normalizeExtensionTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $t = strtolower((string) $token);
            $t = preg_replace('/[^a-z0-9_-]/', '', $t) ?? '';
            if ($t !== '') {
                $out[] = substr($t, 0, 32);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @brief Normalize grantee identifiers from query or form input.
     * @param array<int|string, mixed> $tokens Raw tokens.
     * @return array<int, int>
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function normalizeGranteeIds(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $id = (int) $token;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @brief Parse an optional datetime for listing filters (datetime-local or date).
     * @param string $raw Raw query value.
     * @return \DateTimeImmutable|null
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function parseListingDateTime(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @brief Remove a single active filter chip for listing query params.
     * @param SharedFileOwnerListCriteria $criteria Current criteria.
     * @param string $which Chip identifier.
     * @param int|null $granteeRemoveId Optional grantee id when which is grantee.
     * @return array<string, mixed>
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function listingQueryWithoutChip(SharedFileOwnerListCriteria $criteria, string $which, ?int $granteeRemoveId = null): array
    {
        $p = $criteria->toQueryParams();
        switch ($which) {
            case 'q':
                unset($p['q']);
                break;
            case 'filter_public':
                unset($p['filter_public']);
                break;
            case 'filter_has_grant':
                unset($p['filter_has_grant']);
                break;
            case 'uploaded_after':
                unset($p['uploaded_after']);
                break;
            case 'uploaded_before':
                unset($p['uploaded_before']);
                break;
            case 'updated_after':
                unset($p['updated_after']);
                break;
            case 'updated_before':
                unset($p['updated_before']);
                break;
            case 'expires_after':
                unset($p['expires_after']);
                break;
            case 'expires_before':
                unset($p['expires_before']);
                break;
            case 'ext_all':
                unset($p['ext']);
                break;
            case 'grantee':
                if ($granteeRemoveId === null || !isset($p['grantee']) || !is_array($p['grantee'])) {
                    break;
                }
                $next = array_values(array_diff(array_map(static fn ($v): int => (int) $v, $p['grantee']), [$granteeRemoveId]));
                unset($p['grantee']);
                foreach ($next as $gid) {
                    $p['grantee'][] = $gid;
                }
                break;
            case 'advanced_all':
                foreach (['uploaded_after', 'uploaded_before', 'updated_after', 'updated_before', 'expires_after', 'expires_before', 'filter_has_grant'] as $gk) {
                    unset($p[$gk]);
                }
                unset($p['grantee']);
                break;
            default:
                break;
        }

        return $p;
    }

    /**
     * @brief Build chip metadata for toolbar display and removal links.
     * @param SharedFileOwnerListCriteria $criteria Current listing criteria.
     * @param array<int, string> $granteeLabels Grantee display labels keyed by user id.
     * @return array<int, array{label_key: string, label_params: array<string, string>, query: array<string, mixed>}>
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function buildListingChipDescriptors(SharedFileOwnerListCriteria $criteria, array $granteeLabels): array
    {
        $chips = [];
        if (trim($criteria->searchQuery) !== '') {
            $chips[] = [
                'label_key' => 'files.chips.search',
                'label_params' => ['%value%' => $criteria->searchQuery],
                'query' => $this->listingQueryWithoutChip($criteria, 'q'),
            ];
        }
        if ($criteria->filterPublic === 'yes') {
            $chips[] = [
                'label_key' => 'files.chips.filter_public_yes',
                'label_params' => [],
                'query' => $this->listingQueryWithoutChip($criteria, 'filter_public'),
            ];
        } elseif ($criteria->filterPublic === 'no') {
            $chips[] = [
                'label_key' => 'files.chips.filter_public_no',
                'label_params' => [],
                'query' => $this->listingQueryWithoutChip($criteria, 'filter_public'),
            ];
        }
        if ($criteria->extensionFilters !== []) {
            $chips[] = [
                'label_key' => 'files.chips.extensions',
                'label_params' => ['%list%' => implode(', ', array_map(static fn (string $e): string => '.'.$e, $criteria->extensionFilters))],
                'query' => $this->listingQueryWithoutChip($criteria, 'ext_all'),
            ];
        }
        if ($criteria->filterHasGrant === 'yes') {
            $chips[] = [
                'label_key' => 'files.chips.filter_has_grant_yes',
                'label_params' => [],
                'query' => $this->listingQueryWithoutChip($criteria, 'filter_has_grant'),
            ];
        } elseif ($criteria->filterHasGrant === 'no') {
            $chips[] = [
                'label_key' => 'files.chips.filter_has_grant_no',
                'label_params' => [],
                'query' => $this->listingQueryWithoutChip($criteria, 'filter_has_grant'),
            ];
        }
        if ($criteria->uploadedAfter instanceof \DateTimeImmutable) {
            $chips[] = [
                'label_key' => 'files.chips.uploaded_after',
                'label_params' => ['%date%' => $criteria->uploadedAfter->format('Y-m-d H:i')],
                'query' => $this->listingQueryWithoutChip($criteria, 'uploaded_after'),
            ];
        }
        if ($criteria->uploadedBefore instanceof \DateTimeImmutable) {
            $chips[] = [
                'label_key' => 'files.chips.uploaded_before',
                'label_params' => ['%date%' => $criteria->uploadedBefore->format('Y-m-d H:i')],
                'query' => $this->listingQueryWithoutChip($criteria, 'uploaded_before'),
            ];
        }
        if ($criteria->updatedAfter instanceof \DateTimeImmutable) {
            $chips[] = [
                'label_key' => 'files.chips.updated_after',
                'label_params' => ['%date%' => $criteria->updatedAfter->format('Y-m-d H:i')],
                'query' => $this->listingQueryWithoutChip($criteria, 'updated_after'),
            ];
        }
        if ($criteria->updatedBefore instanceof \DateTimeImmutable) {
            $chips[] = [
                'label_key' => 'files.chips.updated_before',
                'label_params' => ['%date%' => $criteria->updatedBefore->format('Y-m-d H:i')],
                'query' => $this->listingQueryWithoutChip($criteria, 'updated_before'),
            ];
        }
        if ($criteria->expiresAfter instanceof \DateTimeImmutable) {
            $chips[] = [
                'label_key' => 'files.chips.expires_after',
                'label_params' => ['%date%' => $criteria->expiresAfter->format('Y-m-d H:i')],
                'query' => $this->listingQueryWithoutChip($criteria, 'expires_after'),
            ];
        }
        if ($criteria->expiresBefore instanceof \DateTimeImmutable) {
            $chips[] = [
                'label_key' => 'files.chips.expires_before',
                'label_params' => ['%date%' => $criteria->expiresBefore->format('Y-m-d H:i')],
                'query' => $this->listingQueryWithoutChip($criteria, 'expires_before'),
            ];
        }
        foreach ($criteria->granteeUserIds as $gid) {
            $label = $granteeLabels[$gid] ?? (string) $gid;
            $chips[] = [
                'label_key' => 'files.chips.grantee',
                'label_params' => ['%name%' => $label, '%id%' => (string) $gid],
                'query' => $this->listingQueryWithoutChip($criteria, 'grantee', $gid),
            ];
        }

        return $chips;
    }

    /**
     * @brief Build listing criteria from the current query string bag with support for neutral sort state.
     * @param ParameterBag $query Query string bag.
     * @return SharedFileOwnerListCriteria
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function parseListingCriteriaFromQueryBag(ParameterBag $query): SharedFileOwnerListCriteria
    {
        $search = trim((string) $query->get('q', ''));

        $sort = strtolower(trim((string) $query->get('sort', '')));
        $dir = strtolower(trim((string) $query->get('dir', '')));
        $sortAllowed = \in_array($sort, self::LISTING_SORT_FIELDS, true);
        $dirAllowed = \in_array($dir, ['asc', 'desc'], true);
        if (!$sortAllowed || !$dirAllowed) {
            $sort = '';
            $dir = '';
        }
        if ($sort === 'type') {
            $sort = 'ext';
        }

        $filterPublic = strtolower(trim((string) $query->get('filter_public', '')));
        if (!\in_array($filterPublic, ['', 'yes', 'no'], true)) {
            $filterPublic = '';
        }

        $view = strtolower(trim((string) $query->get('view', 'list')));
        if (!\in_array($view, ['list', 'grid'], true)) {
            $view = 'list';
        }

        $extRaw = $query->get('ext');
        $extList = [];
        if (is_array($extRaw)) {
            $extList = $extRaw;
        } elseif (is_string($extRaw) && $extRaw !== '') {
            $extList = [$extRaw];
        }

        $filterHasGrant = strtolower(trim((string) $query->get('filter_has_grant', '')));
        if (!\in_array($filterHasGrant, ['', 'yes', 'no'], true)) {
            $filterHasGrant = '';
        }

        $grantRaw = $query->get('grantee');
        if (!is_array($grantRaw)) {
            $grantRaw = $grantRaw !== null && $grantRaw !== '' ? [$grantRaw] : [];
        }
        $granteeUserIds = $this->normalizeGranteeIds($grantRaw);

        $listingScope = strtolower(trim((string) $query->get('listing_scope', '')));
        if (!\in_array($listingScope, ['both', 'owned', 'shared'], true)) {
            $listingScope = 'both';
        }

        return new SharedFileOwnerListCriteria(
            $search,
            $sort,
            $dir,
            $filterPublic,
            $this->normalizeExtensionTokens($extList),
            $view,
            $filterHasGrant,
            $granteeUserIds,
            $this->parseListingDateTime((string) $query->get('uploaded_after', '')),
            $this->parseListingDateTime((string) $query->get('uploaded_before', '')),
            $this->parseListingDateTime((string) $query->get('updated_after', '')),
            $this->parseListingDateTime((string) $query->get('updated_before', '')),
            $this->parseListingDateTime((string) $query->get('expires_after', '')),
            $this->parseListingDateTime((string) $query->get('expires_before', '')),
            $listingScope,
        );
    }

    /**
     * @brief Sort shared folder rows by owner label with deterministic fallback order.
     * @param array<int, array{id:int,name:string}> $sharedForMeFolders Shared folders keyed by id.
     * @param array<int, string> $ownerLabelsByFolderId Owner labels indexed by folder id.
     * @param string $sortDirection Requested sort direction.
     * @return array<int, array{id:int,name:string}>
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function sortSharedFoldersByOwnerLabel(
        array $sharedForMeFolders,
        array $ownerLabelsByFolderId,
        string $sortDirection
    ): array {
        $directionMultiplier = strtoupper($sortDirection) === 'ASC' ? 1 : -1;

        uasort(
            $sharedForMeFolders,
            static function (array $leftFolder, array $rightFolder) use ($ownerLabelsByFolderId, $directionMultiplier): int {
                $leftId = (int) ($leftFolder['id'] ?? 0);
                $rightId = (int) ($rightFolder['id'] ?? 0);
                $leftLabel = mb_strtolower(trim((string) ($ownerLabelsByFolderId[$leftId] ?? '')), 'UTF-8');
                $rightLabel = mb_strtolower(trim((string) ($ownerLabelsByFolderId[$rightId] ?? '')), 'UTF-8');
                $labelComparison = $leftLabel <=> $rightLabel;
                if ($labelComparison !== 0) {
                    return $labelComparison * $directionMultiplier;
                }
                if ($leftId !== $rightId) {
                    return ($leftId <=> $rightId) * $directionMultiplier;
                }

                return strcmp((string) ($leftFolder['name'] ?? ''), (string) ($rightFolder['name'] ?? '')) * $directionMultiplier;
            }
        );

        return $sharedForMeFolders;
    }

    /**
     * @brief Parse optional public expiration from the client (prefer ISO-8601 with Z/offset from JS; legacy naive datetime-local strings use the PHP default timezone).
     * @param string $raw Raw request value.
     * @return \DateTimeImmutable|null Parsed instant or null when empty/invalid.
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function parseOptionalExpiresAt(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @brief Parse comma or whitespace separated grantee identifiers.
     * @param string $raw Raw grantee list.
     * @return array<int, int>
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function parseGranteeIdList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $ids[] = (int) $part;
        }

        return $ids;
    }

    /**
     * @brief Application-level max assembled upload size (chunked uploads).
     * @param void No input parameter.
     * @return int
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function resolveAppMaxUploadBytes(): int
    {
        return self::MAX_UPLOAD_BYTES;
    }

    /**
     * @brief Resolve max bytes for a single whole-file HTTP upload (legacy endpoint).
     * @param void No input parameter.
     * @return int
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function resolveSingleRequestMaxUploadBytes(): int
    {
        $limits = [self::MAX_UPLOAD_BYTES];
        $phpUploadMax = $this->parseIniSizeToBytes((string) ini_get('upload_max_filesize'));
        $phpPostMax = $this->parseIniSizeToBytes((string) ini_get('post_max_size'));
        if ($phpUploadMax > 0) {
            $limits[] = $phpUploadMax;
        }
        if ($phpPostMax > 0) {
            $limits[] = $phpPostMax;
        }

        return max(1, min($limits));
    }

    /**
     * @brief Resolve max bytes allowed per chunk HTTP request from PHP ini caps.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function resolveMaxChunkRequestBytes(): int
    {
        $limits = [];
        $phpUploadMax = $this->parseIniSizeToBytes((string) ini_get('upload_max_filesize'));
        $phpPostMax = $this->parseIniSizeToBytes((string) ini_get('post_max_size'));
        if ($phpUploadMax > 0) {
            $limits[] = $phpUploadMax;
        }
        if ($phpPostMax > 0) {
            $limits[] = $phpPostMax;
        }

        if ($limits === []) {
            return 32 * 1024 * 1024;
        }

        return max(1, min($limits));
    }

    /**
     * @brief Parse a PHP ini size token (K/M/G suffix) into bytes.
     * @param string $raw Ini value token.
     * @return int
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function parseIniSizeToBytes(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (!preg_match('/^(\d+)([KMG]?)$/i', $raw, $m)) {
            return 0;
        }
        $base = (int) $m[1];
        $unit = strtoupper($m[2] ?? '');

        return match ($unit) {
            'G' => $base * 1024 * 1024 * 1024,
            'M' => $base * 1024 * 1024,
            'K' => $base * 1024,
            default => $base,
        };
    }

    /**
     * @brief Remove disk object and dependent grants or challenges for a file.
     * @param SharedFile $sharedFile Shared file aggregate.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function removeSharedFileAggregate(SharedFile $sharedFile): void
    {
        $path = $sharedFile->getStoragePath();
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        $token = $sharedFile->getPublicToken();
        $this->shareGrantRepository->deleteBySharedFileId((int) $sharedFile->getId());
        $this->publicDownloadChallengeRepository->deleteByPublicToken($token);
        $this->entityManager->remove($sharedFile);
        $this->entityManager->flush();
    }

    /**
     * @brief Apply persisted folder-level share policies to one newly uploaded file; friends grants copy active sibling expiry or skip when all prior grants expired.
     * @param SharedFile $sharedFile Newly created shared file.
     * @param Folder $targetFolder Folder where the file was uploaded.
     * @param int $ownerUserId Owner user identifier.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function applyFolderPoliciesToUploadedFile(SharedFile $sharedFile, Folder $targetFolder, int $ownerUserId): void
    {
        if ($targetFolder->isPublicShareEnabled()) {
            $this->publicShareService->enablePublic($sharedFile, $targetFolder->getPublicShareExpiresAt());
        }

        $subtreeFolders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $targetFolder);
        $folderIds = [];
        foreach ($subtreeFolders as $subFolder) {
            $fid = $subFolder->getId();
            if ($fid !== null && $fid > 0) {
                $folderIds[] = $fid;
            }
        }
        $newFileId = (int) $sharedFile->getId();

        $granteeIntents = [];
        foreach ($targetFolder->getFriendsShareUserIds() as $granteeUserId) {
            if ($granteeUserId <= 0 || $granteeUserId === $ownerUserId) {
                continue;
            }
            $hasPriorGrantInSubtree = $this->shareGrantRepository->hasAnyGrantForOwnerFolderSubtreeGrantee($ownerUserId, $folderIds, $granteeUserId, $newFileId);
            $activeTemplateGrant = $this->shareGrantRepository->findOneActiveGrantForOwnerFolderSubtreeGrantee($ownerUserId, $folderIds, $granteeUserId, $newFileId);
            if ($hasPriorGrantInSubtree && !$activeTemplateGrant instanceof ShareGrant) {
                continue;
            }
            $expiresAt = $activeTemplateGrant instanceof ShareGrant ? $activeTemplateGrant->getExpiresAt() : null;
            $granteeIntents[] = [
                'user_id' => $granteeUserId,
                'expires_at' => $expiresAt,
            ];
        }
        if ($granteeIntents !== []) {
            $this->friendsShareService->applyFriendsIntent($sharedFile, $granteeIntents, false);
        }

    }

    /**
     * @brief Resolve effective grantee user id for shared-folder endpoints in admin contexts.
     * @param Request $request HTTP request.
     * @param bool $fromQuery When true read context from query string (GET); else from request body.
     * @return int|null Effective grantee id, or null when godview all-users misses a valid subject_user.
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function tryResolveEffectiveGranteeIdForAdminSubject(Request $request, bool $fromQuery = false): ?int
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $selfId = (int) $actor->getId();
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $selfId;
        }
        $adminContextRaw = $fromQuery ? $request->query->get('admin_context') : $request->request->get('admin_context');
        $adminViewScopeRaw = $fromQuery ? $request->query->get('admin_view_scope') : $request->request->get('admin_view_scope');
        $isGodviewAllUsers = (string) $adminContextRaw === '1' && (string) $adminViewScopeRaw === 'all';
        $raw = $fromQuery ? $request->query->get('subject_user') : $request->request->get('subject_user');
        if ($raw === null || '' === trim((string) $raw)) {
            return $isGodviewAllUsers ? null : $selfId;
        }
        $subjectId = (int) $raw;
        if ($subjectId <= 0) {
            return $isGodviewAllUsers ? null : $selfId;
        }
        $subject = $this->userRepository->find($subjectId);
        if (!$subject instanceof User) {
            return $isGodviewAllUsers ? null : $selfId;
        }

        return $subjectId;
    }

    /**
     * @brief Resolve shared-folder access context for the provided grantee.
     * @param int $folderId Folder identifier.
     * @param int $granteeUserId Effective grantee user identifier.
     * @return array{folder: Folder, folders: array<int, Folder>, files: array<int, SharedFile>}|null
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function resolveSharedFolderAccess(int $folderId, int $granteeUserId): ?array
    {
        $folder = $this->folderRepository->find($folderId);
        if (!$folder instanceof Folder) {
            return null;
        }
        $folders = $this->folderTreeService->collectSubtreeFolders($folder->getOwnerUserId(), $folder);
        $folderIds = [];
        foreach ($folders as $subFolder) {
            $subFolderId = $subFolder->getId();
            if ($subFolderId !== null && $subFolderId > 0) {
                $folderIds[] = $subFolderId;
            }
        }
        $sharedForGrantee = $this->sharedFileRepository->findSharedForGranteeAll($granteeUserId, null);
        $files = array_values(array_filter(
            $sharedForGrantee,
            static function (SharedFile $sharedFile) use ($folderIds): bool {
                $sharedFolderId = $sharedFile->getFolder()?->getId();

                return $sharedFolderId !== null && in_array($sharedFolderId, $folderIds, true);
            }
        ));
        if ($files === []) {
            return null;
        }

        return [
            'folder' => $folder,
            'folders' => $folders,
            'files' => $files,
        ];
    }

}
