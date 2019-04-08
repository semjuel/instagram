<?php

namespace App\Controller\Api;

use App\Controller\JsonController;
use App\Dataset\Collection\CreateCollection;
use App\Entity\Collection\Collection;
use App\Entity\Organization;
use App\Entity\Project;
use App\Entity\User;
use App\Http\Exception\NotValidatedHttpException;
use App\Repository\CollectionRepository;
use App\Repository\OrganizationRepository;
use App\Repository\ProjectRepository;
use Nelmio\ApiDocBundle\Annotation\Model;
use Nelmio\ApiDocBundle\Annotation\Security;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Swagger\Annotations as SWG;

/**
 * Class CollectionController.
 */
class CollectionController extends JsonController
{
    const VIDEO_VALUE = 'video';

    /**
     * @var Organization
     */
    private $organization;

    /**
     * @var Project
     */
    private $project;

    /**
     * @var Collection
     */
    private $collection;

    /**
     * Create new collection.
     *
     * @param Request $request
     * @param string  $id
     *   Organization id.
     * @param string  $projectId
     *   Project id.
     *
     * @return JsonResponse
     *
     * @Route("/organizations/{id}/projects/{projectId}/collections", name="create-collection", methods={"POST"})
     *
     * @SWG\Parameter(
     *     name="body",
     *     in="body",
     *     description="Collection entity",
     *     required=true,
     *     @SWG\Schema(
     *          type="object",
     *          ref=@Model(type=CreateCollection::class, groups={"create"})
     *     ),
     * )
     * @SWG\Response(response=JsonResponse::HTTP_CREATED, description="Returns created collection.",
     *
     *     @Model(type=Collection::class, groups={"safe"})
     * )
     * @SWG\Response(response=JsonResponse::HTTP_UNPROCESSABLE_ENTITY, description="Unprocessable Entity")
     * @SWG\Response(response=JsonResponse::HTTP_FORBIDDEN, description="Access denied")
     * @SWG\Response(response=JsonResponse::HTTP_NOT_FOUND, description="Not found, in case organization or project doesn't exists")
     * @SWG\Tag(name="collections")
     *
     * @Security(name="Bearer")
     */
    public function create(Request $request, string $id, string $projectId): JsonResponse
    {
        $this->validateAccess($id, $projectId);
        $user = $this->getUser();

        $dataset = $this->deserialize($request->getContent(), CreateCollection::class);

        $token = $dataset->instagram;
        unset($dataset->instagram);
        $errors = $this->validate($dataset);
        if ($errors->count()) {
            throw new NotValidatedHttpException($errors);
        }

        $result = file_get_contents('https://api.instagram.com/v1/users/self/media/recent/?access_token=' . $token);
        try {
            $images = $this->parseResult($result);
        }
        catch (\Exception $e) {
            $this->sendLog('errors', $user->getId(), $e->getMessage());
        }

        $entity = $this->getDatasetExporter()->export($dataset, Collection::class);
        $entity->setCreatedBy($user);
        $entity->setOrganization($this->organization);
        $entity->setProject($this->project);

        $this->getManager()->persist($entity);
        $this->getManager()->flush();

        // Download images from Instagram and attach them to the Collection entity.
        $this->getImageHelper()->fetchImages($entity, $images);

        return $this->respondCreated($entity);
    }

    /**
     * @param $result
     * @return array
     * @throws \Exception
     */
    private function parseResult($result)
    {
        $images = [];

        $data = json_decode($result, true);

        if (is_null($data)) {
            return $images;
        }

        if (!isset($data['data'])) {
            throw new \Exception('There is no field "data"');
        }

        foreach ($data['data'] as $value) {
            $id = $value['id'];

            if (isset($value['carousel_media'])) {
                foreach ($value['carousel_media'] as $carouselMedia) {
                    if ($carouselMedia['type'] == self::VIDEO_VALUE) {
                        $images[$id][] = self::VIDEO_VALUE;
                        continue;
                    }

                    if (!isset($carouselMedia['images']) && isset($carouselMedia['videos'])) {
                        $images[$id][] = self::VIDEO_VALUE;
                        continue;
                    }

                    $images[$id][] = $carouselMedia['images']['standard_resolution']['url'];
                }
            } else {

                if ($value['type'] == self::VIDEO_VALUE) {
                    $images[$id] = self::VIDEO_VALUE;
                    continue;
                }

                if (!isset($value['images']) && isset($value['videos'])) {
                    $images[$id] = self::VIDEO_VALUE;
                    continue;
                }

                $images[$id] = $value['images']['standard_resolution']['url'];
            }
        }
        return $images;
    }

    /**
     * Validate access to the organization, project and collection.
     *
     * @param string $id
     *   Organization id.
     * @param string $projectId
     *   Project id.
     * @param int    $collectionId
     *   Collection id.
     */
    private function validateAccess(string $id, string $projectId, $collectionId = 0)
    {
        $user = $this->getUser();

        // Only authenticated user can see this information.
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        // Validate if user has access to this organization.
        $userOrganizationId = null;
        $organization = $user->getOrganization();
        if ($organization) {
            $userOrganizationId = $organization->getId();
        }

        // Only user attached to this organization or super admin has access to it.
        if ($id !== $userOrganizationId) {
            if (!$user->hasRole(User::ROLE_SUPER_ADMIN)) {
                throw new AccessDeniedHttpException();
            }
        }

        $isValid = Uuid::isValid($id);
        if (!$isValid) {
            throw new NotFoundHttpException();
        }

        $isValid = Uuid::isValid($projectId);
        if (!$isValid) {
            throw new NotFoundHttpException();
        }

        /** @var OrganizationRepository $repository */
        $repository = $this->getRepository(Organization::class);
        $organization = $repository->findOneById($id);
        $this->organization = $organization;
        if (!$organization) {
            throw new NotFoundHttpException();
        }

        // Validate if project is attached to the organization.
        /** @var ProjectRepository $repository */
        $repository = $this->getRepository(Project::class);
        $project = $repository->findOneById($projectId);
        if (!$project) {
            throw new NotFoundHttpException();
        }
        $this->project = $project;

        if ($organization->getId() !== $project->getOrganization()->getId()) {
            throw new NotFoundHttpException();
        }

        if ($collectionId) {
            /** @var CollectionRepository $repository */
            $repository = $this->getRepository(Collection::class);
            $collection = $repository->findOneById($collectionId);
            if (!$collection) {
                throw new NotFoundHttpException();
            }

            $this->collection = $collection;
        }
    }
}
