<?php

namespace AppBundle\Controller\Rest;

use AppBundle\Entity\RegisterPage;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\View;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use FOS\RestBundle\Util\Codes;
use Symfony\Component\Validator\Constraints\Date;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

/**
 *
 * @Rest\RouteResource("Ordering")
 * @Rest\Prefix("api")
 * @Rest\NamePrefix("ordering_rest_")
 */
class OrderingRestController extends FOSRestController
{
    /**
     * @Rest\View(serializerGroups={"ordering"})
     * @Rest\Post("/ordering/{kindergartenId}/{year}/{first}", defaults={"kindergartenId" = null,  "first" = null })
     */
    public function getAction($kindergartenId = null, $year, $first = null)
    {

        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // get request
        $request = $this->get('request')->request;

        // get filters
        $requestData = $request->all();

        $filters = $requestData['filter'];

        $districtId = $requestData['district'];

        // default value for kindergarten
        $kindergarten = null;

        if($kindergartenId != 0){

            // find kindergarten by id
            $kindergarten = $em->getRepository("AppBundle:Kindergarten")->find($kindergartenId);

            // check kindergarten
            if(!$kindergarten){

                // not found error
                return new Response('kindergarten not found', Codes::HTTP_NOT_FOUND);
            }
        }

        // get kindergarten register pages
        $registerPages = $em->getRepository("AppBundle:RegisterPage")->
            getRegisterPagesByYearAndKindergarten($kindergarten, $year, $first, $filters,$districtId);

        // get artahert id
        $orderReplaceCount = $em->getRepository("AppBundle:RegisterPage")->
        getOrderReplaceByYearAndKindergarten($kindergarten, $year,$districtId);

        $count = $em->getRepository("AppBundle:RegisterPage")->
        getRegisterPagesByYearAndKindergartenCount($kindergarten, $year, $filters,$districtId);

        return  array($registerPages,$count,$orderReplaceCount);
    }

    /**
     * @Rest\View(serializerGroups={"android"})
     * @Rest\Post("/check-child")
     */
    public function checkChildAction()
    {
        // get response
        $request = $this->get('request');

        // get data from request
        $data = $request->request->all();

        // check birth certificate
        if(array_key_exists('birth_certificate', $data)){

            $birthCertificate = $data['birth_certificate'];
        }
        else {

            return new JsonResponse('birth_certificate not found', Codes::HTTP_NOT_FOUND);
        }

        // check birth date
        if(array_key_exists('birth_date', $data)){

            $birthDate = new \DateTime($data['birth_date']);
        }
        else {

            return new JsonResponse('birth_date not found', Codes::HTTP_NOT_FOUND);
        }

        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // get child
        $child = $em->getRepository("AppBundle:Child")->
            findChildByDateAndCertificate($birthDate, $birthCertificate);

        return $child;
    }

    /**
     * @Rest\View(serializerGroups={"ordering"})
     * @Rest\Post("/ordering/change-position/{kindergartenId}/{year}", defaults={})
     */
    public function changePositionAction($kindergartenId, $year)
    {
        if ($this->isGranted("ROLE_KINDERGARTEN_SUPER_ADMIN")){
            // get entity manager
            $em = $this->getDoctrine()->getManager();

            // get request
            $request = $this->get('request')->request;

            // get filters
            $requestData = $request->all();

            $orderChild = $requestData['orderChild'];

            $position = array();

            foreach($orderChild as $child){
                $position[$child[0]['id']] = $child['position'];
            }

            $em->getRepository("AppBundle:KindergartenRegisterPage")->changePosition($kindergartenId,$year,$position);

            return true;

        } return false;

    }



    /**
     * @Rest\View(serializerGroups={"ordering"})
     * @Rest\Post("/ordering/reject-order/{registerPage}")
     * @ParamConverter("registerPage", class="AppBundle:RegisterPage")
     */
    public function rejectOrderAction(RegisterPage $registerPage)
    {

        $confirm = 0;
        // get response
        $request = $this->get('request');

        // get data from request
        $data = $request->request->all();

        $messageDeny = $data['message'];

        if ($this->isGranted("ROLE_KINDERGARTEN_SUPER_ADMIN") or $this->isGranted("ROLE_ADMINISTRATOR") ){
            // get entity manager
            $child = $registerPage->getChild();

            $child->setOrderReplace(false);
            $child->setOrderReplaceStatus(false);
            // generate
            $em = $this->getDoctrine()->getManager();

            $em->persist($child);
            $em->flush();

            $kgService = $this->get('kg_service');
            $kgService->addEventToChild('replace_ordering_deny', $registerPage->getChild());


            if(count($registerPage->getKgRegisterPage()->getValues()) == 1){

                $kgRegAll =  $registerPage->getKgRegisterPage()->getValues();
                $kgReg = $kgRegAll[0];
                $kidUser = $kgReg->getKindergarten()->getUser();

                $kgService = $this->get('kg_service');

                $kgService->sendNoteConfirmOrder($kidUser, $registerPage->getChild(),$confirm,$messageDeny);

            }

            return true;
        }
        return false;
    }
}