<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Child;
use AppBundle\Entity\KindergartenRegisterPage;
use AppBundle\Entity\OrderOut;
use AppBundle\Entity\RegisterPage;
use AppBundle\Entity\Kindergarten;
use AppBundle\Entity\LeavePage;
use AppBundle\Form\CheckChildType;
use AppBundle\Form\ChildType;
use AppBundle\Form\OrderingPageType;
use AppBundle\Form\OrderOutType;
use AppBundle\Model\ChildModel;
use JMS\DiExtraBundle\Annotation\Service;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use JMS\SecurityExtraBundle\Annotation\Secure;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\DateTime;

/**
 * @Route("/order")
 *
 * Class ChildController
 * @package AppBundle\Controller
 */
class OrderController extends Controller
{
    /**
     * @Secure(roles="ROLE_ORDERING_INDEX")
     * @Route("/{year}/{kindergartenId}", requirements={"year" = "\d+", "kindergartenId" = "\d+"}, defaults={"year" = 2015 , "kindergartenId"= null},  name="ordering_index")
     * @Template()
     */
    public function indexAction($kindergartenId = null)
    {

        // get current user
        $currentUser = $this->getUser();

        // get entity manager
        $em = $this->getDoctrine()->getManager();

        //check user
        $check = $this->checkUser($currentUser,$kindergartenId);

        if(!$check){
            throw $this->createNotFoundException('Kindergarten not found');
        }

        // empty value for kindergarten
        $kindergarten = null;
        $district = false;
        $districtId = 0;

        if($kindergartenId){

            // find kindergarten by id
            $kindergarten = $em->getRepository("AppBundle:Kindergarten")->find($kindergartenId);
        }
        else{

            // check role
            if($this->isGranted("ROLE_KINDERGARTEN")){

                // get user kindergarten
                $kindergarten = $currentUser->getKindergarten();
            } elseif ($this->isGranted('ROLE_DISTRICT')){

                $currentUser = $this->getUser();
                $districtUser = $currentUser->getDistrictUser();
                $districtId = $districtUser->getDistrict();
                $district = true;
            }
        }

        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // get exist years
        $existYears = $em->getRepository("AppBundle:RegisterPage")->getRegisterPagesCountInYear($kindergarten,$district,$districtId);

        // init return data
        $returnArray = array('years' => $existYears, 'kindergarten' => $kindergarten,"districtId" => $districtId);

        // check role, and return twig
        if(is_null($kindergartenId)){

            return $returnArray;

        }
        else{

            return $this->render('AppBundle:Ordering:indexForDepartment.html.twig', $returnArray);
        }

    }

    /**
     *
     * This function is used to show child`s profile page
     *
     * @Secure(roles="ROLE_ORDERING_VIEW")
     * @Route("/view/{id}/{kindergartenId}", requirements={"id" = "\d+", "kindergartenId" = "\d+"}, defaults={"kindergartenId"= null}, name="archive_page_view")
     * @Security("child.check(user) or has_role('ROLE_KINDERGARTEN_SUPER_ADMIN') or has_role('ROLE_ADMINISTRATOR') or has_role('ROLE_KINDERGARTEN') or has_role('ROLE_ORDERING_REPLACE')")
     * @ParamConverter("child", class="AppBundle:Child")
     * @Template()
     */
    public function viewAction(Child $child, $kindergartenId = null)
    {
        // empty position
        $position = null;
        $logs = null;
        $districtId = 0;
        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // default value for kindergarten
        $kindergarten = null;

        // check kindergarten
        if($kindergartenId){

            // find kindergarten by id
            $kindergarten = $em->getRepository("AppBundle:Kindergarten")->find($kindergartenId);
        }
        else{

            if($this->isGranted("ROLE_MUNICIPALITY")){

                // get current user
                $currentUser = $this->getUser();

                // get user kindergarten
                $kindergarten = $currentUser->getKindergarten();
            } elseif ($this->isGranted("ROLE_DISTRICT")){
                $currentUser = $this->getUser();
                $districtUser = $currentUser->getDistrictUser();
                $districtId = $districtUser->getDistrict();
            }
        }

        // get child registration page
        $registerPage = $child->getRegisterPage();

        // check register page
        if(!$registerPage){

            throw $this->createNotFoundException('Child have not ordering page');
        }

        // get position
        $position = $em->getRepository('AppBundle:RegisterPage')
            ->getPositionInKindergarten($kindergarten, $registerPage);

        $logs = $em->getRepository('AppBundle:Child')->getLogChildEntry($child);

        $serializer = $this->container->get('serializer');
        $logsJson = $serializer->serialize($logs, 'json');

        $acceptDate = $em->getRepository('AppBundle:AcceptDate')->getAccess();

        $year = $child->getDateOfBirth()->format("Y");


        $newChildGreen = $em->getRepository("AppBundle:RegisterPage")->getChildInKindergartenGreen($kindergarten,$year);
        $newChildBlue = $em->getRepository("AppBundle:RegisterPage")->getChildInKindergartenBlue($kindergarten,$year);
        $allowGreen = false;
        $allowBlue = false;
        $dateNow = new \DateTime();

        if (!empty($newChildGreen) and $newChildGreen[0]["child_id"] == $child->getId()){
            $allowGreen = true;
        }

        if (!empty($newChildBlue) and $newChildBlue[0]["child_id"] == $child->getId()){
            $allowBlue = true;
        }
        // get artahert count
        $orderReplaceCount = $em->getRepository("AppBundle:RegisterPage")->getOrderReplaceByYearAndKindergarten($kindergarten, $year);


        if($kindergarten == null and $this->isGranted("ROLE_KINDERGARTEN") == true )
        {
            return $this->render('AppBundle:Ordering:errorOrdering.html.twig');
        }
        else{

            return array('child' => $child, 'position' => $position, 'kindergarten' => $kindergarten,'districtId' => $districtId,'logs'=>$logsJson,'acceptDate'=>$acceptDate,
                'allowWhite'=>$allowGreen,'allowBlue'=>$allowBlue,'dateNow'=>$dateNow,'orderReplaceCount'=>$orderReplaceCount);

        }
    }

    /**
     * This action is used to create ordering page from yerevan.am
     *
     * @Secure(roles="ROLE_USER")
     * @Route("/online-create",  name="ordering_online_create")
     * @Template()
     */
    public function onlineCreateAction(Request $request)
    {

        if ($this->isGranted("ROLE_KINDERGARTEN_SUPER_ADMIN")){
            $allowDateChange = true;
        } else {
            $allowDateChange = false;
        }
        // get all kindergartens

        $user = $this->container->get('security.context')->getToken()->getUser();
        $kindergartens = $user->getKindergarten();
        $checked = $kindergartens->getId();

        // get child from request
        $child = $request->get('child');
        $registerPage = new RegisterPage();
        if($child){

            $registerPage->setChild($child);
        }
        // create form
        $form = $this->createForm(new OrderingPageType(),$registerPage, array('allowDateChange'=>$allowDateChange));

        // check method
        if($request->isMethod("POST")){

            // get data from form
            $form->handleRequest($request);

            // check form
            if($form->isValid()) {

                // get data
                $data = $form->getData();

                // get child
                $child = $data->getChild();

                // get token
                $token = $this->generateAndSetToken($child);

                if ($child->getCertificateFile() == null) {

                    // return error message
                    $this->get('session')->getFlashBag()->add(
                        'error', $this->get('translator')->trans('error_child_certificateFile', array(), 'messages'));
                }
                else {

                    // check and save kindergartens
                    $this->checkAndSetOrRemoveKindergartens($checked, $data);

                    // check and save data
                    $this->checkAndSaveData($data);


                    $kgService = $this->get('kg_service');
                    $kgService->addEventToChild('created_ordering_page', $child);
                    // send note
                    $kgService->sendNote(
                        $this->generateUrl('archive_page_view', array('id' => $child->getId())),
                        'ordering_page');

                    return $this->redirectToRoute('active_child', array('token' => $token));
                }

            }
        }

        // generate action
        $action = $this->generateUrl('ordering_online_create');

        return array('form' => $form->createView(),
            'action' => $action, 'kindergartens' => $kindergartens);
    }

    /**
     * This action is used to edit ordering page from yerevan.am
     *
     * @Secure(roles="ROLE_USER")
     * @Route("/online-edit/{id}", requirements={"id" = "\d+"},   name="ordering_online_edit")
     * @ParamConverter("registerPage", class="AppBundle:RegisterPage")
     * @Template()
     */
    public function onlineEditAction(Request $request, RegisterPage $registerPage)
    {
        $kindergartens = null;
        $kgService = $this->get('kg_service');
        $kgRegisterPages = $kgService->getCheckedKindergarten($registerPage->getId());

        $checked = array();

        if(count($kgRegisterPages)){
            foreach($kgRegisterPages as $kgRegisterPage){

                // get checked kindergarten
                $checkedKindergarten = $kgRegisterPage->getKindergarten();
                array_push($checked,$checkedKindergarten->getId());
            }
        }
        // get old email
        $email = $registerPage->getChild()->getEmail();

        if ($this->isGranted("ROLE_KINDERGARTEN_SUPER_ADMIN")){
            $allowDateChange = true;
        } else {
            $allowDateChange = false;
        }

        // create form
        $form = $this->createForm(new OrderingPageType(),$registerPage,array('allowDateChange'=>$allowDateChange) );
        $em = $this->getDoctrine()->getEntityManager();
        $childRealData = $em->getRepository("AppBundle:Child")->findChildById($registerPage->getChild()->getId());
        $childRealYear = $childRealData->getDateOfBirth()->format("Y");

        // check method
        if($request->isMethod("POST")){

            // get data from form
            $form->handleRequest($request);

            // check form
            if($form->isValid()){

                // get child
                $child = $registerPage->getChild();

                // get new email
                $newEmail = $child->getEmail();

                if(trim($newEmail) != trim($email)){

                    // set child to inactive
                    $child->setStatus(Child::INACTIVE);

                    // get token
                    $token = $this->generateAndSetToken($child);
                }

                // get checked kindergartens data

                if ($childRealYear == $child->getDateOfBirth()->format("Y"))
                    $dateChange = false;
                else
                    $dateChange = true;

                // check and save data
                $this->checkAndSaveData($registerPage,$dateChange);

                // set child data to the session
                $request->getSession()->set('childData',
                    array(
                        'dateOfBirth' => $child->getDateOfBirth(),
                        'certificate' => $child->getBirthCertificate()
                    ));


                $kgService = $this->get('kg_service');
                // send note
                $kgService->sendDataChangeNote(
                    $this->generateUrl('archive_page_view', array('id' => $child->getId())),
                    'data_change',$child->getId());

                // redirect to view
                if ($this->isGranted("ROLE_KINDERGARTEN")){
                    $user = $this->container->get('security.context')->getToken()->getUser();
                    $kindergartens = $user->getKindergarten();
                    $kgId = $kindergartens->getId();
                    return $this->redirectToRoute('archive_page_view',array('id' => $child->getId(),'kindergartenId' => $kgId));
                } else {
                    return $this->redirectToRoute('archive_page_view',array('id' => $child->getId()));
                }
            }
            else{

                // return error message
                $this->get('session')->getFlashBag()->add(
                    'error', $this->get('translator')->trans('error_msg', array(), 'messages'));
            }
        }

        // generate action
        $action = $this->generateUrl('ordering_online_edit', array('id' => $registerPage->getId()));

        return array('form' => $form->createView(),
            'action' => $action,
            'kindergartens' => $kindergartens);
    }

    /**
     * This action is used to create ordering page from yerevan.am
     *
     * @Secure(roles="ROLE_USER")
     * @Route("/online-add-kindergarten/{id}",  name="ordering_online_add_kindergarten")
     * @ParamConverter("registerPage", class="AppBundle:RegisterPage")
     */
    public function addOrderKindergartenAction(RegisterPage $registerPage){

        $em = $this->getDoctrine()->getManager();

        $email = $registerPage->getChild()->getEmail();

        $child = $registerPage->getChild();

        //remove Leave page
        if($registerPage->getChild()->getLeavePage()){
            $leave = $registerPage->getChild()->getLeavePage();
            $em->remove($leave);
            $em->flush();
            $registerPage->getChild()->setLeavePage(null);
        }

        // get new email
        $newEmail = $child->getEmail();

        if(trim($newEmail) != trim($email)){

            // set child to inactive
            $child->setStatus(Child::INACTIVE);

            // get token
            $token = $this->generateAndSetToken($child);
        }

        $user = $this->container->get('security.context')->getToken()->getUser();
        $kindergartens = $user->getKindergarten();
        $kidId = $kindergartens->getId();

        $kgService = $this->get('kg_service');
        $kgRegisterPages = $kgService->getCheckedKindergarten($registerPage->getId());

        $checked = array();

        if(count($kgRegisterPages)){

            foreach($kgRegisterPages as $kgRegisterPage){

                // get checked kindergarten
                $checkedKindergarten = $kgRegisterPage->getKindergarten();
                if ($kgRegisterPage->getStatus() != 1)
                    array_push($checked,$checkedKindergarten->getId());
            }
        }
        array_push($checked,$kidId);
        $kidList = implode(',',$checked);

        // check and save kindergartens


        $this->checkAndSetOrRemoveKindergartens($kidList, $registerPage);

        foreach($kgRegisterPages as $kgRegisterPage){

            if($kgRegisterPage->getKindergarten()->getId() != $kidId  AND $kgRegisterPage->getStatus() != 1  )
            {

                $kindergarten = $kgRegisterPage->getKindergarten();

                // get kindergarten service
                $kgService = $this->container->get('kg_service');

                // get reason text
                $reasonText =  KindergartenRegisterPage::REFUSEDKID;

                $kgService->updatePosition($registerPage, KindergartenRegisterPage::OUT_ORDER, $reasonText, $kindergarten);

                $users = array();
                array_push($users,$kindergarten->getUser());
                $kgService->sendNoteRegisterPage($users,$child);
            }


        }


        // check and save data
        $this->checkAndSaveData($registerPage);
        $flush = 1;
        $kgService->addEventToChild('created_ordering_page',$child,null,null,$flush);

        // redirect to view
        return $this->redirectToRoute('archive_page_view',array('id' => $child->getId(),'kindergartenId' => $kidId));

    }

    /**
     * This action is used to create ordering page from leave page
     *
     * @Secure(roles="ROLE_USER")
     * @Route("/online-add-kindergarten_leave/{id}",  name="ordering_online_add_kindergarten_leave")
     * @ParamConverter("leavePage", class="AppBundle:LeavePage")
     */
    public function addLeaveOrderKindergartenAction(LeavePage $leavePage){

        $email = $leavePage->getChild()->getEmail();

        $child = $leavePage->getChild();


        $user = $this->container->get('security.context')->getToken()->getUser();
        $kindergartens = $user->getKindergarten();
        $kidId = $kindergartens->getId();


        $checked = array();
        array_push($checked,$kidId);
        $kidList = implode(',',$checked);

        if ($child->getRegisterPage())
            $registerPage  = $child->getRegisterPage();
        else {
            $registerPage = new RegisterPage();

            $registerPage->setChild($child);
        }


        // check and save kindergartens
        $this->checkAndSetOrRemoveKindergartens($kidList, $registerPage);
        // check and save data

        $this->checkAndSaveData($registerPage);

        $kgService = $this->get('kg_service');

        $kgService->addEventToChild('created_ordering_page',$child);

        return $this->redirectToRoute('archive_page_view',array('id' => $child->getId(),'kindergartenId' => $kidId));
    }


    /**
     * This action is used to remove child ordering page
     *
     * @Secure(roles="ROLE_ORDERING_PAGES_DELETE")
     * @Route("/ordering-delete/{id}/{kindergartenId}/{year}", requirements={"id" = "\d+"},  name="ordering_delete")
     * @ParamConverter("child", class="AppBundle:Child")
     * @Template()
     */
    public function deleteOrderingAction(Child $child, $kindergartenId, $year)
    {
        // generate
        $em = $this->getDoctrine()->getManager();

        // get child register page
        $registerPage = $child->getRegisterPage();

        // check register page
        if($registerPage){

            // remove register page
            $em->remove($registerPage);
        }

        // flush all data
        $em->flush();

        // get child archive page
        $archivePage = $child->getArchivePage();

        // get child leave page
        $leavePage = $child->getLeavePage();

        // get exist page
        $existPage = $child->getExistPage();

        // check archive page
        if(!$archivePage && !$leavePage && !$existPage){

            // get kindergarten service
            $kgService = $this->get('kg_service');

            // remove child, ih child hav`nt any page
            $kgService->removeChild($child);
        }

        // return to check email
        if ($kindergartenId == 0)
            return $this->redirectToRoute('ordering_index', array('year'=>$year));
        else
            return $this->redirectToRoute('ordering_index', array('year'=>$year, 'kindergartenId'=> $kindergartenId));
    }

    /**
     * This action is used to show ordering page from yerevan.am
     *
     * @Secure(roles="ROLE_USER")
     * @Route("/online-view/{id}", requirements={"id" = "\d+"}, name="online_page_view")
     * @ParamConverter("child", class="AppBundle:Child")
     * @Template()
     */
    public function onlineViewAction(Child $child, Request $request)
    {
        // get session
        $session = $request->getSession();

        // check session
        if($session->has('childData')){

            $childData = $session->get('childData');

            if($this->checkValid($childData)){

                // remove session
                $session->remove('childData');

                // return to template
                return array('child' => $child);
            }
        }

        // return to edit form
        return $this->redirectToRoute('ordering_online');
    }


    /**
     * This action is used to out child from order
     *
     * @Secure(roles="ROLE_ORDERING_OUT")
     * @Route("/out-order/{kindergarten}/{registerPage}", requirements={"kindergarten" = "\d+", "registerPage" = "\d+" }, name="out_from_order")
     * @ParamConverter("kindergarten", class="AppBundle:Kindergarten")
     * @ParamConverter("registerPage", class="AppBundle:RegisterPage")
     * @Template()
     */
    public function outFromOrderAction(Request $request,Kindergarten $kindergarten, RegisterPage $registerPage, $reason = null )
    {

        $em = $this->getDoctrine()->getManager();

        $orderOut = new OrderOut();

        $form = $this->createForm(new OrderOutType(), $orderOut);

        // check method
        if($request->isMethod("POST")){

            // get data from form
            $form->handleRequest($request);

            // check form
            if($form->isValid()){

                // get data
                $data = $form->getData();

                if ($data->getOutFile() == null){

                    $this->get('session')->getFlashBag()->add(
                        'error', $this->get('translator')->trans('error_child_file', array(), 'messages'));

                } else {
                    $this->checkAndSaveFiles($data->getOutFile(), $orderOut);


                    $child = $registerPage->getChild();
                    $data->setChild($child);
                    $data->setKindergarten($kindergarten);

                    $em->persist($data);
                    $em->flush();

                    $orderFile = $orderOut->getOutFile();

                    $orderFile->setOrderOut($orderOut);

                    $em->persist($orderFile);
                    $em->flush();

                    // get kindergarten service
                    $kgService = $this->container->get('kg_service');

                    // get reason text
                    $reasonText =  $data->getType() == 1 ? KindergartenRegisterPage::ESTABLISH : KindergartenRegisterPage::REFUSED;

                    // update position
                    $kgService->updatePosition($registerPage, KindergartenRegisterPage::OUT_ORDER, $reasonText, $kindergarten);

                    // get child birth date
                    $year = $registerPage->getChild()->getDateOfBirth();

                    // get year
                    $year = $year->format('Y');
                    // return to view form
                    return $this->redirectToRoute('ordering_index', array('year' => $year));

                }


            }
        }

        return array('form' => $form->createView());
    }


    /**
     * This action is used to out child from order
     *
     * @Secure(roles="ROLE_KINDERGARTEN_SUPER_ADMIN,ROLE_ADMINISTRATOR")
     * @Route("/confirm-order/{registerPage}/{confirm}", requirements={"registerPage" = "\d+", "confirm" = "0|1" }, name="order_confirm")
     * @ParamConverter("registerPage", class="AppBundle:RegisterPage")
     * @Template()
     */
    public function orderConfirmAction(RegisterPage $registerPage, $confirm)
    {
        if ($confirm == 0){


            $child = $registerPage->getChild();

            $child->setOrderReplace(false);
            $child->setOrderReplaceStatus(false);
            // generate
            $em = $this->getDoctrine()->getManager();

            $em->persist($child);
            $em->flush();

            $kgService = $this->get('kg_service');
            $kgService->addEventToChild('replace_ordering_deny', $registerPage->getChild());

        } else if ($confirm == 1){
            $child = $registerPage->getChild();

            $child->setOrderReplace(false);
            $child->setOrderReplaceStatus(true);

            // generate
            $em = $this->getDoctrine()->getManager();

            $em->persist($child);
            $em->flush();

            $kgService = $this->get('kg_service');
            $kgService->addEventToChild('replace_ordering_apply', $registerPage->getChild());
        }

        if(count($registerPage->getKgRegisterPage()->getValues()) == 1){

            $kgRegAll =  $registerPage->getKgRegisterPage()->getValues();
            $kgReg = $kgRegAll[0];
            $kidUser = $kgReg->getKindergarten()->getUser();

            $kgService = $this->get('kg_service');

            $kgService->sendNoteConfirmOrder($kidUser, $registerPage->getChild(),$confirm);

        }
        return $this->redirectToRoute('archive_page_view', array('id' => $registerPage->getChild()->getId()));
    }

    /**
     * This action is used to show attach files
     *
     * @Secure(roles="ROLE_USER")
     * @Route("/attach-file/{id}/{kindergartenId}", requirements={"id" = "\d+","kindergartenId" = "\d+"},defaults={"kindergartenId"= null},  name="ordering_attach_file")
     * @ParamConverter("child", class="AppBundle:Child")
     * @Template()
     */
    public function attachFileAction(Child $child,$kindergartenId = null)
    {

        // empty position
        $position = null;
        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // default value for kindergarten
        $kindergarten = null;

        // check kindergarten
        if($kindergartenId){

            // find kindergarten by id
            $kindergarten = $em->getRepository("AppBundle:Kindergarten")->find($kindergartenId);
        }
        else{

            if($this->isGranted("ROLE_MUNICIPALITY")){

                // get current user
                $currentUser = $this->getUser();

                // get user kindergarten
                $kindergarten = $currentUser->getKindergarten();
            }
        }

        // get child registration page
        $registerPage = $child->getRegisterPage();

        // check register page
        if(!$registerPage){

            throw $this->createNotFoundException('Child have not ordering page');
        }

        // get position
        $position = $em->getRepository('AppBundle:RegisterPage')
            ->getPositionInKindergarten($kindergarten, $registerPage);

        return array('child' => $child, 'position' => $position, 'kindergarten' => $kindergarten);
    }


    /**
     * This function is used to check, and save, or remove kindergartens from register page
     *
     * @param $checked
     * @param $registerPage
     */
    private function checkAndSetOrRemoveKindergartens($checked, $registerPage,$dateChange = false)
    {


        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // get kindergarten service
        $kgService = $this->container->get('kg_service');

        // get all selected data
        $ids = explode(',', $checked);

        // get all exist ids

        $existIds = $registerPage->getAllKindergartensId();

        // get kindergarten
        $addIds = array_diff($ids, $existIds);

        // get removed kindergartens
        $removeIds = array_diff($existIds, $ids);

        // check if new kindergarten is added

        if($addIds){


            // get kindergartens by id
            $kindergartens = $em->getRepository('AppBundle:Kindergarten')->getAllByIds($addIds);

            // check kindergarten
            if($kindergartens){

                // loop for kindergarten
                foreach($kindergartens as $kindergarten){

                    // create new kindergarten register page
                    $kgRegisterPage = new KindergartenRegisterPage();

                    // add kg register page to kindergarten
                    $kindergarten->addKgRegisterPage($kgRegisterPage);

                    // add kg register page to register page
                    $registerPage->addKgRegisterPage($kgRegisterPage);

                    // get all kindergartens
                    $position = $kgService->getLastPosition($kindergarten, $kgRegisterPage->getYear());
                    // set position
                    $kgRegisterPage->setPosition($position + 1);

                    // persist data

                    $em->persist($kgRegisterPage);
                    $em->persist($kindergarten);
                }
            }
        }


        if ($dateChange) {

            $kindergartens = $em->getRepository('AppBundle:Kindergarten')->getAllByIds($existIds);

            // check kindergarten
            if ($kindergartens) {

                // loop for kindergarten
                foreach ($kindergartens as $kindergarten) {

                    $kgRegisterPage = $kindergarten->getKgRegisterPageByRegisterId($registerPage->getId());

                    $em->remove($kgRegisterPage);
                    $em->flush();
                }

                // loop for kindergarten
                foreach ($kindergartens as $kindergarten) {

                    // create new kindergarten register page
                    $kgRegisterPage = new KindergartenRegisterPage();

                    // add kg register page to kindergarten
                    $kindergarten->addKgRegisterPage($kgRegisterPage);

                    // add kg register page to register page
                    $registerPage->addKgRegisterPage($kgRegisterPage);

                    // get all kindergartens
                    $position = $kgService->getLastPosition($kindergarten, $kgRegisterPage->getYear());

                    // set position
                    $kgRegisterPage->setPosition($position + 1);

                    // persist data
                    $em->persist($kgRegisterPage);
                    $em->persist($kindergarten);
                    $em->flush();
                }
            }

        }


    }

    /**
     *
     * This function is used to  get data from form and save it
     *
     * @param $data
     * @param bool|false $dateChange
     */
    private function checkAndSaveData($data,$dateChange = false)
    {
        // get child
        $child = $data->getChild();

        $kgService = $this->container->get('kg_service');

        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // get child photo
        $photo = $child->getPhoto();
        // save photo
        $this->checkAndSaveFiles($photo, $child);

        $child->setPhoto($photo);

        // get parent file
        $parentFile = $child->getParentFile();
        // save parent file
        $this->checkAndSaveFiles($parentFile, $child);

        // get second parent file
        $secondParentFile = $child->getSecondParentFile();
        // save second parent file
        $this->checkAndSaveFiles($secondParentFile, $child);

        // get certificate file
        $certificateFile = $child->getCertificateFile();
        // save certificate file
        $this->checkAndSaveFiles($certificateFile, $child);

        // persist data
        $em->persist($data);
        $em->flush();

        $existIds = $data->getAllKindergartensId();

        if ($dateChange) {
            $kindergartens = $em->getRepository('AppBundle:Kindergarten')->getAllByIds($existIds);

            // check kindergarten
            if ($kindergartens) {

                // loop for kindergarten
                foreach ($kindergartens as $kindergarten) {

                    $kgRegisterPage = $kindergarten->getKgRegisterPageByRegisterId($data->getId());

                    $em->remove($kgRegisterPage);
                    $em->flush();
                }

                // loop for kindergarten
                foreach ($kindergartens as $kindergarten) {

                    // create new kindergarten register page
                    $kgRegisterPage = new KindergartenRegisterPage();

                    // add kg register page to kindergarten
                    $kindergarten->addKgRegisterPage($kgRegisterPage);


                    // add kg register page to register page
                    $data->addKgRegisterPage($kgRegisterPage);

                    // get all kindergartens
                    $position = $kgService->getLastPosition($kindergarten, $kgRegisterPage->getYear());

                    // set position
                    $kgRegisterPage->setPosition($position + 1);

                    // persist data
                    $em->persist($kgRegisterPage);
                    $em->persist($kindergarten);
                    $em->flush();
                }
            }

        }

        // return success message
        $this->get('session')->getFlashBag()->clear();
        $this->get('session')->getFlashBag()->add(
            'success', $this->get('translator')->trans('success_msg', array(), 'messages'));

    }

    /**
     * This action is used to remove child certificate
     *
     * @Secure(roles="ROLE_ORDERING_EMAIL_PARENT")
     * @Route("/check-sms/{registerPage}/{kindergarten}", requirements={"registerPage" = "\d+", "kindergarten" = "\d+"}, name="send_sms_to_parent")
     * @ParamConverter("registerPage", class="AppBundle:RegisterPage")
     * @ParamConverter("kindergarten", class="AppBundle:Kindergarten")
     * @Template()
     */
    public  function SmsToParentAction(RegisterPage $registerPage, Kindergarten $kindergarten)
    {
        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // get kindergarten service
        $kgService = $this->get('kg_service');

        $child = $registerPage->getChild();
        $verifyPhone = $child->getVerifyPhone();
        if($verifyPhone){

            $smsCount = $child->getSendSmsCount();
            if($smsCount <= 3){
                $sending =   $kgService->sendSms($verifyPhone,'parent_sms_text',$kindergarten->getName());
                if($sending['status'] == true){
                    $child->setSendSmsCount($smsCount+1);
                    $em->persist($child);
                    $em->flush();
                    $this->get('session')->getFlashBag()->add(
                        'success', $this->get('translator')->trans('success_msg_sms', array(), 'messages'));
                    // set event
                    $kgService->addEventToChild('send_ordering_sms', $child);
                    // redirect to link
                }
            }else{
                $this->get('session')->getFlashBag()->add(
                    'error', $this->get('translator')->trans('error_msg_sms', array(), 'messages'));
            }

        }


        return $this->redirect($_SERVER['HTTP_REFERER']);



    }


    /**
     * This action is used to show history
     *
     * @Secure(roles="ROLE_ORDERING_HISTORY_VIEW")
     * @Route("/history/{id}/{kindergartenId}", requirements={"id" = "\d+","kindergartenId" = "\d+"}, defaults={"kindergartenId"= null}, name="order_history")
     * @ParamConverter("child", class="AppBundle:Child")
     * @Template()
     */
    public function historyAction(Child $child,$kindergartenId = null)
    {
        // empty position
        $position = null;
        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // default value for kindergarten
        $kindergarten = null;
        $event = null;

        // check kindergarten
        if($kindergartenId){

            // find kindergarten by id
            $kindergarten = $em->getRepository("AppBundle:Kindergarten")->find($kindergartenId);

        }
        else{

            if($this->isGranted("ROLE_MUNICIPALITY")){

                // get current user
                $currentUser = $this->getUser();

                // get user kindergarten
                $kindergarten = $currentUser->getKindergarten();
            }
        }

        $event = $em->getRepository("AppBundle:Kindergarten")->findAllHistory($child->getId());

        return array('child' => $child,'kindergarten' => $kindergarten,'event'=>$event);
    }

    /**
     * @Secure(roles="ROLE_ORDERING_CHANGE")
     * @Route("/change-position/{year}/{kindergartenId}", requirements={"year" = "\d+", "kindergartenId" = "\d+"}, defaults={"year" = 2015 , "kindergartenId"= null},  name="ordering_position_update")
     * @Template()
     */
    public function updatePositionAction($kindergartenId)
    {

        $currentUser = $this->getUser();

        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // empty value for kindergarten
        $kindergarten = null;
        $district = false;
        $districtId = 0;

        if($kindergartenId){

            // find kindergarten by id
            $kindergarten = $em->getRepository("AppBundle:Kindergarten")->find($kindergartenId);
        }

        // get entity manager
        $em = $this->getDoctrine()->getManager();

        // get exist years
        $existYears = $em->getRepository("AppBundle:RegisterPage")->getRegisterPagesCountInYear($kindergarten,$district,$districtId);

        // init return data
        $returnArray = array('years' => $existYears, 'kindergarten' => $kindergarten,"districtId" => $districtId);

        return $returnArray;

    }

    public function checkUser($user,$kindergartenId)
    {
        $em = $this->getDoctrine()->getManager();
        $check = true;
        if($kindergartenId){
            $kindergarten = $em->getRepository("AppBundle:Kindergarten")->find($kindergartenId);
            if ($user->getKindergarten()){
                $check = $user->getKindergarten()->getId() == $this->getId();
            } else if ($user->getDistrictUser()){
                $check =  (string) $user->getDistrictUser()->getDistrictId() == $kindergarten->getDistrictId();
            }
        }

        return $check;
    }

}
