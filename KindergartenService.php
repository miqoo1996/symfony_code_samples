<?php

namespace AppBundle\Services;

use AppBundle\Entity\Child;
use AppBundle\Entity\Event;
use AppBundle\Entity\Kindergarten;
use AppBundle\Form\ChildType;
use AppBundle\Model\ChildModel;
use Doctrine\ORM\EntityManager;
use FOS\RestBundle\Util\Codes;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerBuilder;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tmcycyit\NotificationBundle\Entity\PreparedNotification;
use AppBundle\Entity\KindergartenRegisterPage;

class KindergartenService
{
    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    protected  $container;


    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected  $em;


    /**
     * @param Container $container
     * @param EntityManager $em
     */
    public function __construct(Container $container, EntityManager $em)
    {
        $this->container = $container;
        $this->em = $em;
    }

    /**
     * This function is used to get higher position
     *
     * @param $kindergarten
     * @param $year
     * @return mixed
     */
    public function getLastPosition($kindergarten, $year)
    {

        // get entity manager
        $em = $this->em;

        // get position
        $position = $em->getRepository('AppBundle:Kindergarten')->findHigherPosition($kindergarten, $year);

        // check position
        if($position && $position['position'] > 0 ){

            // return position
            return $position['position'];
        }

        return 0;

    }

    /**
     * This function is used to get higher position
     *
     * @param $kindergarten
     * @param $year
     * @return mixed
     */
    public function getNegativePosition($kindergarten, $year)
    {
        // get entity manager
        $em = $this->em;

        // get position
        $position = $em->getRepository('AppBundle:Kindergarten')->findHigherNegativePosition($kindergarten, $year);

        // check position
        if($position  ){

            // return position
            return $position['position'];
        }

        return 0;

    }

    /**
     * This function is used to get all kindergartens, with checked
     *
     * @param $registerId
     * @return Response
     */
    public function getCheckedKindergarten($registerId)
    {
        // get entity manager
        $em = $this->em;

        // get kindergartens
        $kgRegisterPage = null;

        if($registerId){
            // get registerPage
            $registerPage = $em->getRepository('AppBundle:RegisterPage')->find($registerId);

            // check register page
            if(!$registerPage){

                return new Response(Codes::HTTP_NOT_FOUND);
            }
            $kgRegisterPage = $registerPage->getKgRegisterPage();
        }

        return $kgRegisterPage ? $kgRegisterPage : null;
    }

    /**
     *  Add events to child
     *
     * @param $action
     * @param $child
     * @param $kindergarten
     * @param array $options
     *
     */
    public function addEventToChild($action, $child, $kindergarten = null, array $options = null)
    {
        // get entity manager
        $em = $this->em;

        $user = $this->container->get('security.context')->getToken()->getUser();

        // get translator
        $tr = $this->container->get('translator');

        // create event
        $event = new Event();

        // set kindergarten
        $event->setKindergarten($kindergarten ? $kindergarten : $user->getKindergarten());

        // set user
        $event->setUser($user);

        // set action
        $event->setAction($tr->trans($action, $options ? $options : array(), 'events'));

        // set event
        $event->setChild($child);


        // persist data
        $em->persist($event);
        if($action != 'created_page_from_ordering_page')
        {
            $em->flush();
        }
    }

    /**
     * This function is used to update position
     *
     * @param $registerPage
     * @param $status
     * @param $reason
     * @param null $kindergarten
     */
    public function updatePosition($registerPage, $status, $reasonText, $kindergarten = null)
    {

        if( $reasonText == KindergartenRegisterPage::ESTABLISH  ){
            $reason = 1;
        }elseif($reasonText == KindergartenRegisterPage::REFUSED){
            $reason = 2 ;
        }elseif($reasonText == KindergartenRegisterPage::REFUSEDKID){
            $reason = 3 ;
        }

        // get entity manager
        $em = $this->em;

        if($kindergarten){

            // get kgRegister page by kindergarten
            $kgRegisterPages = $em->getRepository("AppBundle:KindergartenRegisterPage")
                        ->getKgRegisterPage($kindergarten, $registerPage);
        }
        else{

            // get all register page
            $kgRegisterPages = $registerPage->getKgRegisterPage();
        }

        // check kg register pages
        if($kgRegisterPages){

            // loop for kg register page
            foreach($kgRegisterPages as $kgRegisterPage){

                if (!$kgRegisterPage->getStatus()){
                    // if not kindergarten
                    if(!$kindergarten){

                        // get from kg register page
                        $kindergarten = $kgRegisterPage->getKindergarten();
                    }

                    // get year
                    $year = $kgRegisterPage->getYear();

                    // get position
                    $position = $kgRegisterPage->getPosition();

                    // get negative position
                    $negativePosition = $this->getNegativePosition($kindergarten->getId(), $year);

                    // set position
                    $kgRegisterPage->setPosition($negativePosition - 1);

                    // set status
                    $kgRegisterPage->setStatus($status);

                    // set reason text
                    $kgRegisterPage->setReason($reasonText);

                    // persist data
                    $em->persist($kgRegisterPage);

                    // update position
                    $em->getRepository("AppBundle:KindergartenRegisterPage")
                        ->updatePosition($kindergarten->getId(), $position, $year, $kgRegisterPage->getId());
                   // var_dump(dump($registerPage->getChild()));die();
                    if ($reason == 1)
                        $this->addEventToChild('out_ordering_page_first',$registerPage->getChild());
                    else if ($reason == 2)
                        $this->addEventToChild('out_ordering_page_second',$registerPage->getChild());
                    else if ($reason == 3)
                        $this->addEventToChild('out_ordering_page_kindergarten',$registerPage->getChild(),$kindergarten);

                    $em->flush();
                }

            }
        }
    }

    /**
     * This function is used to check child wit owr db, then to service db
     *
     * @param ChildModel $childModel
     * @return null
     */
    public function checkChild(ChildModel $childModel)
    {
        // get em
        $em = $this->em;

        // check in own repository
        $child = $em->getRepository("AppBundle:Child")->findByChildModel($childModel);

        // if is not in our db
        if(!$child){
            // check child from api
//            $child = $this->getChildFromApi($childModel);

            $child = new Child();
            $child->setName($childModel->name);
            $child->setSurname($childModel->surname);
            $child->setSsn($childModel->ssn);
            $child->setBirthCertificate($childModel->birthCertificate);
            $child->setDateOfBirth($childModel->dateOfBirth);
            $child->setCertificateType($childModel->certificateType);
            $child->setCertificateDate($childModel->certificateDate);

        }
//        else {
//            $childExist = $em->getRepository("AppBundle:ExistPage")->checkChildExist($child);
//
//            if (!$childExist){
//                $child.
//            }
//        }

        return $child ? $child : null;
    }

    /**
     * This function is used to check child wit owr db, then to service db
     *
     * @param ChildModel $childModel
     * @return null
     */
    public function checkChildExist($childModel)
    {
        // get em
        $em = $this->em;

        // check in own repository
        $child = $em->getRepository("AppBundle:ExistPage")->checkChildExist($childModel);

        return $child ? true : false;
    }

    /**
     * This function is used to check child wit owr db, then to service db
     *
     * @param ChildModel $childModel
     * @return null
     */
    public function checkChildArchiv($childModel)
    {
        // get em
        $em = $this->em;

        // check in own repository
        $child = $em->getRepository("AppBundle:ArchivePage")->checkChildArchiv($childModel);

        return $child ? true : false;
    }

    /**
     * This function is used to check child wit owr db, then to service db
     *
     * @param ChildModel $childModel
     * @return null
     */
    public function checkChildFinished($childModel)
    {
        // get em
        $em = $this->em;

        // check in own repository
        $child = $em->getRepository("AppBundle:FinishedPage")->checkChildFinished($childModel);

        return $child ? true : false;
    }


    /**
     * This function is used to check child wit owr db, then to service db
     * @param ChildModel $childModel
     * @param Kindergarten $kindergarten
     * @return null
     */
    public function checkChildRegisterInKindergarten($childModel,$kindergarten)
    {
        // get em
        $em = $this->em;

        // check in own repository
        $child = $em->getRepository("AppBundle:RegisterPage")->checkChildRegisterInKindergarten($childModel,$kindergarten);

        return $child ? true : false;
    }

    /**
     * This function is used to check child wit owr db, then to service db
     * @param ChildModel $childModel
     * @return null
     */
    public function checkChildRegisterInKindergartens($childModel)
    {
        // get em
        $em = $this->em;

        // check in own repository
        $child = $em->getRepository("AppBundle:RegisterPage")->checkChildRegisterInKindergartens($childModel);

        return $child ? true : false;
    }



    /**
     * This function is used to remove child
     *
     * @param Child $child
     */
    public function removeChild(Child $child)
    {
        // get entity manager
        $em = $this->em;

        // remove child
        $em->remove($child);

        $em->flush();
    }



    /**
     * This function is used to send notification
     *
     * @param $link
     * @param $name
     * @param $actionCode
     */
    public function sendNote($link, $actionCode, $name = null)
    {
       // var_dump($link, $actionCode);die("a");

        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');

        // get prepared notes
        $preparedNotification = $notification->getPreparedNoteByCode($actionCode);
        if($preparedNotification)
        {
            // empty array for receivers
            $receivers = array();

            // get current user
            $currentUser = $this->container->get('security.context')->getToken()->getUser();

            $userInfo = $currentUser->getFirstname() . ' '  . $currentUser->getLastname();

            // empty array for user
            $users = array();
            // check user groups

            foreach($preparedNotification->getUserGroups() as $role)
            {
                // find users by code
                $users = array_merge($users, $em->getRepository('ApplicationUserBundle:User')->findAllByRole($role,$currentUser));
            }

            // if users is defined
            if($users)
            {
                // loop for users
                foreach($users as $user)
                {
                    $receivers[] = $user;
                }
            }

            $replaceContent = array("link" => $link);

        // send note
        $notification->sendNote($users, $preparedNotification, $replaceContent, $userInfo, $name);

        }
    }

    /**
     * This function is used to send notification
     *
     * @param $users
     */
    public function sendNoteExist($users,$child)
    {
        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');

        $systemUser = $em->getRepository('ApplicationUserBundle:User')->findSystemUser();

        $message = $child->getName()." ".$child->getSurname()."ը դուրս է եկել Ձեր մանկապարտեզի ".$child->getDateOfBirth()->format('Y')."թ. հերթից՝ ընդունվելով այլ մանկապարտեզ";
        $notification->sendFastNoteFromUser($message,"Ծանուցում", $users,$systemUser,1);

    }

 /**
     * This function is used to send notification
     *
     * @param $users
     */
    public function sendNoteRegisterPage($users,$child)
    {


        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');

        $systemUser = $em->getRepository('ApplicationUserBundle:User')->findSystemUser();

        $message = $child->getName()." ".$child->getSurname()."ը դուրս է եկել Ձեր մանկապարտեզի ".$child->getDateOfBirth()->format('Y')."թ. հերթից՝ հերթագրվելով այլ մանկապարտեզում";

        $notification->sendFastNoteFromUser($message,"Ծանուցում", $users,$systemUser,1);

    }

    /**
     * This function is used to send notification
     *
     * @param $user
     * @param $child
     * @param $confirm
     */
    public function sendNoteConfirmOrder($user,$child,$confirm,$messageDeny = null)
    {

        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');

        $systemUser = $em->getRepository('ApplicationUserBundle:User')->findSystemUser();
        if($confirm == 0){
            $confirm_name = 'մերժվել';
        }elseif($confirm == 1){
            $confirm_name = 'հաստատվել';
        }
        $message = $child->getName()." ".$child->getSurname()."ի արտահերթության հայտը ".$confirm_name." է " .$messageDeny;

        $notification->sendFastNoteFromUser($message,"Ծանուցում", array($user),$systemUser,1);

    }


    /**
     * This function is used to send notification
     *
     * @param $link
     * @param $name
     * @param $actionCode
     * @param $child
     */
    public function sendDataChangeNote($link, $actionCode,$child, $name = null)
    {

        $em = $this->container->get('doctrine')->getManager();

        $logs = $em->getRepository('AppBundle:Child')->getLogProblemChildEntry($child);

        $errorCount = 0;

        $child = array();

        foreach ($logs as $l) {

            $serializer = $this->container->get('serializer');
            $logsJson = $serializer->serialize($l, 'json');

            $arr = json_decode($logsJson);
            if (isset($child[$arr->object_id])) {
                if (count((array)($arr->data)) != 1)
                    array_push($child[$arr->object_id], $arr->data);

            } else {

                if (count((array)($arr->data)) != 1) {
                    $child[$arr->object_id] = array();
                    array_push($child[$arr->object_id], $arr->data);

                }

            }

        }

        foreach ($child as $k => $c) {

            $error = array();

            foreach ($c as $l) {

                if (isset($l->name))
                    $error['name'] = true;

                if (isset($l->surname))
                    $error['surname'] = true;

                if (isset($l->birthCertificate))
                    $error['birthCertificate'] = true;

                if (isset($l->dateOfBirth))
                    $error['dateOfBirth'] = true;

            }

            if (count($error) >= 3) {

                $errorCount++;

                $this->sendNote($link, $actionCode, $name = null);
            }

        }

    }



    /**
     * This function is used to send notification
     *
     * @param $child
     * @param $systemUser
     * @param $user
     */
    public function sendNoteApproveFrom($child,$systemUser,$user)
    {
        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');
       // $systemUser = $em->getRepository('ApplicationUserBundle:User')->findSystemUser();

        $message = $child->getName()." ".$child->getSurname()."ը դուրս է եկել այս մանկապարտեզից՝ տեղափոխվելով այլ մանկապարտեզ:";
        $notification->sendFastNoteFromUser($message,"Ծանուցում", array($user),$systemUser,1);

    }

    /**
     * This function is used to send notification
     *
     * @param $child
     * @param $systemUser
     * @param $user
     */
    public function sendNoteApproveTo($child,$systemUser,$user)
    {
        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');
        // $systemUser = $em->getRepository('ApplicationUserBundle:User')->findSystemUser();

        $message = $child->getName()." ".$child->getSurname()."ը տեղափոխվել է այս մանկապարտեզի ".$child->getExistPage()->getKindergartenGroup()->getName()." խումբ";
        $notification->sendFastNoteFromUser($message,"Ծանուցում", array($user),$systemUser,1);

    }

    /**
     * This function is used to send notification
     *
     * @param $child
     * @param $systemUser
     * @param $user
     */
    public function sendNoteDismiss($child,$systemUser,$user)
    {
        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');
        // $systemUser = $em->getRepository('ApplicationUserBundle:User')->findSystemUser();

        $message = $child->getName()." ".$child->getSurname()."ի տեղափոխման հայտը մերժվել է։";
        $notification->sendFastNoteFromUser($message,"Ծանուցում", array($user),$systemUser,1);

    }


    /**
     * This function is used to send notification in online registered
     *
     * @param $user
     * @param $child
     */
    public function sendOrderOnline($user,$child)
    {

        // get doctrine
        $em = $this->container->get('doctrine')->getManager();

        // get notification sender service
        $notification = $this->container->get('yitnote');

        $year = $child->getDateOfBirth()->format('Y');

        $systemUser = $em->getRepository('ApplicationUserBundle:User')->findSystemUser();

        $message = "Ստեղծվել է առցանց հերթագրման էջ՝ ".$child->getName()." ".$child->getSurname().", ".$year."թ.";

        $notification->sendFastNoteFromUser($message,"Առցանց հերթագրում", array($user),$systemUser,2);

    }
    

}