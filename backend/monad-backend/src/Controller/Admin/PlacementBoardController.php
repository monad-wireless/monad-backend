<?php

namespace App\Controller\Admin;

use App\Entity\LabPlacement;
use App\Repository\LabPlacementRepository;
use App\Service\MarkerService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The placement board (IP-157, Phase 3): every mirrored card and node of one floor, with the
 * verdict of the join against the live quests, and the print sheet that used to live at
 * `/admin/lab/markers`.
 *
 * Reads only. The mirror is written by the MCP tool `lab_placements_write` from what
 * `monad-knowledge lab placements-export` reads out of PostGIS; nothing on this page can move a
 * card, because this database is not where a card's position is recorded.
 *
 * See QuestBuilderController for why this is `#[AdminRoute]` on a plain controller.
 */
#[IsGranted('ROLE_SUPERADMIN')]
class PlacementBoardController extends AbstractController
{
    /** The floor the sync hint names when the mirror is empty: the one lab this fleet stands in. */
    public const DEFAULT_FLOOR = 'fiit-ground-0';

    /**
     * The quest builder's edit page (Phase 2 lane). Resolved through the router at render time
     * rather than assumed: while that lane has not shipped, a quest is named in plain text.
     */
    private const QUEST_EDIT_ROUTE = 'admin_lab_quests_edit';

    public function __construct(
        private readonly LabPlacementRepository $placements,
        private readonly MarkerService $markers,
        private readonly RouterInterface $router,
    ) {
    }

    #[AdminRoute(path: '/lab/placements', name: 'lab_placements')]
    public function index(Request $request): Response
    {
        return $this->render('admin/placements.html.twig', $this->board($request));
    }

    /**
     * The QR figure the print sheet embeds, served under the ADMIN firewall.
     *
     * `/api/lab/markers/{value}.svg` renders the same bytes, but it sits under `^/api`, which is
     * the stateless JWT firewall: an operator's browser holds an `admin` session cookie and no
     * bearer token, so every `<img>` on the retired `/admin/lab/markers` page 401'd. The sheet
     * therefore asks this controller, which shares the operator's session, and the render stays
     * one function of the payload in `MarkerService::svg`.
     */
    #[AdminRoute(path: '/lab/placements/marker/{value}.svg', name: 'lab_placements_marker_svg', options: ['requirements' => ['value' => '.+'], 'methods' => ['GET']])]
    public function markerSvg(string $value): Response
    {
        return new Response($this->markers->svg($value), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * One QR figure per mirrored card (matched and spare; a node's sticker is a fleet label,
     * not a marker), with label and room, cut guides, and print rules in admin-placements.css.
     *
     * A matched card renders the exact string its live quest names, so the sheet and the step
     * agree byte for byte; a spare card renders the portal's `/m/<key>` grammar, which is what a
     * quest naming it later will be generated with.
     */
    #[AdminRoute(path: '/lab/placements/print', name: 'lab_placements_print')]
    public function printSheet(Request $request): Response
    {
        $board = $this->board($request);

        $sheet = [];
        foreach ($board['rows'] as $row) {
            /** @var LabPlacement $placement */
            $placement = $row['placement'];
            if ($placement->getKind() !== LabPlacement::KIND_CARD) {
                continue;
            }
            $sheet[] = [
                'key' => $placement->getKey(),
                'room' => $placement->getRoom(),
                'value' => $row['values'][0] ?? MarkerService::cardPayload($placement->getKey()),
                'verdict' => $row['verdict'],
                'quests' => array_column($row['quests'], 'name'),
            ];
        }

        return $this->render('admin/placements_print.html.twig', $board + ['sheet' => $sheet]);
    }

    /**
     * Everything both pages need: the floor, its rows with verdicts, the two side lists and the
     * sync facts.
     *
     * @return array<string, mixed>
     */
    private function board(Request $request): array
    {
        $floors = $this->placements->floors();
        $floor = trim((string) $request->query->get('floor', ''));
        if ($floor === '') {
            $floor = $floors[0] ?? self::DEFAULT_FLOOR;
        }

        $drift = $this->markers->driftReport($floor);

        // Verdict per folded key, so the row loop below is one lookup rather than a search.
        $verdicts = [];
        foreach ($drift['matched'] as $m) {
            $verdicts[MarkerService::codeKey($m['key'])] = [
                'verdict' => MarkerService::VERDICT_MATCHED,
                'values' => $m['values'],
                'quests' => $this->linkQuests($m['quests']),
            ];
        }
        foreach ($drift['spare'] as $s) {
            $verdicts[MarkerService::codeKey($s['key'])] = [
                'verdict' => MarkerService::VERDICT_SPARE,
                'values' => [],
                'quests' => [],
            ];
        }

        $rows = [];
        $cards = 0;
        $nodes = 0;
        $withoutRoom = 0;
        foreach ($this->placements->findByFloor($floor) as $placement) {
            $placement->getKind() === LabPlacement::KIND_CARD ? $cards++ : $nodes++;
            if ($placement->getRoom() === null) {
                $withoutRoom++;
            }
            $rows[] = ['placement' => $placement] + ($verdicts[MarkerService::codeKey($placement->getKey())] ?? [
                'verdict' => MarkerService::VERDICT_SPARE,
                'values' => [],
                'quests' => [],
            ]);
        }

        $mismatch = array_map(fn (array $m): array => $m + ['quests_linked' => $this->linkQuests($m['quests'])], $drift['mismatch']);
        $historical = array_map(fn (array $h): array => $h + ['quests_linked' => $this->linkQuests($h['quests'])], $drift['historical']);

        return [
            'floor' => $floor,
            'floors' => $floors,
            'rows' => $rows,
            'counts' => [
                'cards' => $cards,
                'nodes' => $nodes,
                'without_room' => $withoutRoom,
                'matched' => count($drift['matched']),
                'spare' => count($drift['spare']),
                'mismatch' => count($mismatch),
                'historical' => count($historical),
            ],
            'mismatch' => $mismatch,
            'historical' => $historical,
            'synced_at' => $drift['synced_at'],
            'export_command' => sprintf('uv run monad-knowledge lab placements-export --floor %s', $floor),
        ];
    }

    /**
     * Each naming quest with a `url` to the builder's edit page when that route exists at render
     * time and takes an `id`, else `null` (plain text in the template).
     *
     * @param list<array{id: string, name: string, status: string}> $quests
     * @return list<array{id: string, name: string, status: string, url: ?string}>
     */
    private function linkQuests(array $quests): array
    {
        $route = $this->router->getRouteCollection()->get(self::QUEST_EDIT_ROUTE);
        $linkable = $route !== null && in_array('id', $route->compile()->getVariables(), true);

        return array_map(fn (array $q): array => $q + [
            'url' => $linkable && $q['id'] !== '' ? $this->generateUrl(self::QUEST_EDIT_ROUTE, ['id' => $q['id']]) : null,
        ], $quests);
    }
}
