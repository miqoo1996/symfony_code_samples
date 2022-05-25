<?php
	
namespace AppBundle\Entity\Repository;

use AppBundle\Entity\Child;
use AppBundle\Entity\RegisterPage;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Symfony\Component\Validator\Constraints\DateTime;


class RegisterPageRepository extends EntityRepository
{

    /**
     * This function is used to get kindergartens register page count
     * @param $kindergarten
     * @param null $district
     * @param null $districtId
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getRegisterPagesCountInYear($kindergarten,$district = null,$districtId = null)
    {

        $sql = "SELECT YEAR(T.chdob) AS birth, COUNT(T.chid) AS CNT ,
                  COUNT(CASE WHEN YEAR(T.chdob) = YEAR(NOW()) - 2 AND NOW() >= date(T.chdob + INTERVAL 2 YEAR) THEN 1
                             WHEN YEAR(T.chdob) = YEAR(NOW()) - 3 AND NOW() >= date(T.chdob + INTERVAL 3 YEAR) THEN 1
                  ELSE NULL END ) AS lracac ,
                  COUNT(CASE WHEN YEAR(T.chdob) = YEAR(NOW()) - 2 AND NOW() <= date(T.chdob + INTERVAL 2 YEAR) THEN 1
                             WHEN YEAR(T.chdob) = YEAR(NOW()) - 3 AND NOW() <= date(T.chdob + INTERVAL 3 YEAR) THEN 1
                  ELSE NULL END ) AS chlracac              
                FROM (SELECT ch.date_of_birth AS chdob, ch.id AS chid FROM kg_register_page  krp
                      LEFT JOIN register_page rp ON krp.register_page_id = rp.id AND krp.status = 0
                      LEFT JOIN child ch ON rp.child_id = ch.id
                      LEFT JOIN kindergarten k ON k.id = krp.kindergarten_id ";

        if($kindergarten){
            if($district == true){
                $sql = $sql."WHERE k.district_id = :id";
                $sql = $sql." AND ( YEAR(ch.date_of_birth) <= YEAR(NOW()) - 2) ";
                $sql = $sql."GROUP BY rp.id) AS T GROUP BY birth";

                $stmt = $this->getEntityManager()
                    ->getConnection()
                    ->prepare($sql);
                $stmt->bindValue('id',$kindergarten->getDistrict());
                $stmt->execute();
                return $stmt->fetchAll();

            } else {
                $sql = $sql."WHERE k.id = :id ";
                $sql = $sql." AND ( YEAR(ch.date_of_birth) <= YEAR(NOW()) - 2)  ";
                $sql = $sql."GROUP BY rp.id) AS T GROUP BY birth";

                $stmt = $this->getEntityManager()
                    ->getConnection()
                    ->prepare($sql);
                $stmt->bindValue('id',$kindergarten->getId());
                $stmt->execute();
                return $stmt->fetchAll();
            }
        } elseif($district == true){

            $sql = $sql."WHERE k.id = :id";
            $sql = $sql." AND ( YEAR(ch.date_of_birth) <= YEAR(NOW()) - 2) ";
            $sql = $sql."GROUP BY rp.id) AS T GROUP BY birth";

            $stmt = $this->getEntityManager()
                ->getConnection()
                ->prepare($sql);

            if ($districtId)
                $stmt->bindValue('id',$districtId);
            else
                $stmt->bindValue('id',$kindergarten->getDistrict());

            $stmt->execute();
            return $stmt->fetchAll();
        }


        $sql = $sql."GROUP BY rp.id) AS T GROUP BY birth";

        $stmt = $this->getEntityManager()
            ->getConnection()
            ->prepare($sql);

        $stmt->execute();

        return $stmt->fetchAll();

    }

    /**
     * This function is used to get kindergartens register page count
     *
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getRegisterPagesCount()
    {
        $sql = "
              SELECT sum(t2.CNT) as all_orders,Sum(t2.right_order) as right_order
              FROM (
                      SELECT YEAR(T.chdob) AS birth, 
                        COUNT(T.chid) AS CNT ,
                        COUNT(CASE 
                              WHEN  T.chid is not null and T.t_status is not null THEN 1 
                              WHEN YEAR(T.chdob) <= year(DATE(NOW() - INTERVAL 3 YEAR)) AND T.t_status is null THEN 1 
                              ELSE NULL END ) AS right_order
                            
                      FROM (
                          SELECT  k.id,
                                  ch.date_of_birth AS chdob, 
                                  ch.id AS chid,
                                  k.kindergarten_type_id,
                                  t.t_status 
                          FROM kg_register_page  krp
                          LEFT JOIN register_page rp ON krp.register_page_id = rp.id AND krp.status = 0
                          LEFT JOIN child ch ON rp.child_id = ch.id
                          LEFT JOIN kindergarten k ON k.id = krp.kindergarten_id
                          LEFT JOIN (
                              SELECT kindergarten.id, true as t_status  FROM kindergarten 
                              left join kindergarten_group on kindergarten_group.kindergarten_id = kindergarten.id
                              WHERE kindergarten.kindergarten_type_id = 1 and kindergarten_group.kindergarten_group_id = 3
                              GROUP BY kindergarten.id
                          )t on t.id = k.id     
                          GROUP BY rp.id) AS T
                      GROUP BY T.id,birth
                  )t2";


        $stmt = $this->getEntityManager()
            ->getConnection()
            ->prepare($sql);

        $stmt->execute();
        $result = $stmt->fetchAll();
        return $result ? $result[0] : null;

    }

    /**
     * This function is used to get kindergartens register page count kids
     *
     * @return array
     * @param $kids
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getRegisterPagesCountKids($kids = null)
    {

        $sql = "
              SELECT
                k.id,
                count( rp.id) as all_orders
              FROM
              kg_register_page krp
              LEFT JOIN register_page rp ON krp.register_page_id = rp.id AND krp.status = 0
              LEFT JOIN child ch ON rp.child_id = ch.id
              LEFT JOIN kindergarten k ON k.id = krp.kindergarten_id                
        ";

        if($kids){

            $sql .= " WHERE k.id = ".$kids;
        }


        $sql .= " GROUP BY k.id";
        $stmt = $this->getEntityManager()
            ->getConnection()
            ->prepare($sql);

        $stmt->execute();
        $result = $stmt->fetchAll();
        return $result ? $result : null;

    }

    /**
     * This function is used to get kindergartens register page
     * @param $kindergarten
     * @param $year
     * @param $first
     * @param $filters
     * @param $districtId
     * @return array
     */
    public function getRegisterPagesByYearAndKindergarten($kindergarten, $year, $first, $filters,$districtId)
    {
        $maxResult = 6;

        if($first == 0){
            $maxResult = $maxResult * 3;
        }


       $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('rp', 'krp.position', 'k.id as kgId')
            ->from('AppBundle:RegisterPage', 'rp')
            ->leftJoin('rp.kgRegisterPage', 'krp')
            ->leftJoin('krp.kindergarten', 'k')
            ->leftJoin('rp.child', 'ch');

        // check status
        if( array_key_exists('outStatus', $filters) && !is_null($filters['outStatus']) && $filters['outStatus']){

            $this->getEntityManager()->getFilters()->disable('out_of_order');

            $status = $filters['outStatus'];

            if ($status){

                $query
                    ->where('krp.status = 1')
                    ->groupBy('rp.id')
                    ->addOrderBy('krp.position', 'ASC');
                   // ->addOrderBy('ch.id', 'ASC');

            }

        } else {

            $query
                ->where('krp.status = 0')
                ->groupBy('ch.id')
                ->addOrderBy('krp.position', 'ASC')
                ->addOrderBy('ch.id', 'ASC');
        }

        // create query

        // check kindergarten
        if($kindergarten){
            $query
                ->andWhere('k.id = :id')
                ->setParameter('id', $kindergarten->getId());

        }elseif($districtId != 0 ) {

            $query
                ->andWhere('k.districtId = :district_id')
                ->setParameter('district_id', $districtId);
        }

        // check year
        if($year){

            $query
                ->andWhere('YEAR(ch.dateOfBirth) = :birth')
                ->andWhere('k.id IS NOT NULL')
                ->setParameter('birth', (int)$year);

        }

        // check text
        if( array_key_exists('text', $filters) && !is_null($filters['text'])){

            $inputStrings = trim($filters['text']);
            $inputStrings = preg_split("/[\s]+/", $inputStrings);

            if(array_key_exists(1, $inputStrings)){

                $query
                    ->andWhere('ch.name like :textname AND ch.surname like :textsurname ')
                    ->setParameter('textname', $inputStrings[0] . '%')
                    ->setParameter('textsurname', $inputStrings[1] . '%');

            }
            else{
                $query
                    ->andWhere('ch.name like :textname OR ch.surname like :textname ')
                    ->setParameter('textname', $inputStrings[0] . '%');

            }

        }

        if( array_key_exists('gender', $filters) && !is_null($filters['gender'])){

            $status = $filters['gender'];

            if(array_key_exists('0', $status) && !is_null($status['0']) && $status['0']){
                $query
                    ->andWhere('ch.gender = :girl ')
                    ->setParameter('girl', Child::GIRL);
            }

            if(array_key_exists('1', $status) && !is_null($status['1']) && $status['1']){
                $query
                    ->andWhere('ch.gender = :boy ')
                    ->setParameter('boy', Child::BOY);
            }
        }

        if( array_key_exists('city', $filters) && !is_null($filters['city'])){

            $status = $filters['city'];

            if(array_key_exists(3, $status) && !is_null($status[3]) && $status[3]){

                $query
                    ->andWhere('ch.city = :city ')
                    ->setParameter('city', 3);
            }

            if(array_key_exists(4, $status) && !is_null($status[4]) && $status[4]){
                $query
                    ->andWhere('ch.city = :city ')
                    ->setParameter('city', 4);
            }
        }

       // check order replace type
        if( array_key_exists('orderReplace', $filters) && !is_null($filters['orderReplace']) && $filters['orderReplace'] ){
            $query
                ->andWhere('ch.orderReplaceStatus = :orderReplaceStatus ')
                ->setParameter('orderReplaceStatus', true);
        }

        $query
            ->groupBy('rp.id');

       if($first != null){
           $query
               ->setMaxResults($maxResult)
               ->setFirstResult($first);
       }


        return $query->getQuery()->getResult();

    }

    /**
     * This function is used to get kindergartens register page count
     * @param $kindergarten
     * @param $year
     * @param $filters
     * @param $districtId
     * @return int|mixed
     */

  public function getRegisterPagesByYearAndKindergartenCount($kindergarten, $year, $filters,$districtId){


      $query = $this->getEntityManager()
          ->createQueryBuilder()
          ->select('count(rp)')
          ->from('AppBundle:KindergartenRegisterPage', 'kg')
          ->leftJoin('kg.registerPage', 'rp')
          ->leftJoin('rp.child', 'ch')
          ->leftJoin('kg.kindergarten', 'k');



      if( array_key_exists('outStatus', $filters) && !is_null($filters['outStatus']) && $filters['outStatus']) {
          $status = $filters['outStatus'];

          if ($status) {
              $query
                  ->where('kg.status = 1');
          }
      }
      else {
          $query
              ->where('kg.status = 0');

      }


      if($kindergarten){

          $query
              ->andWhere('k.id = :id')
              ->setParameter('id', $kindergarten->getId());
      }
      elseif($districtId != 0 ) {

          $query
              ->andWhere('k.districtId = :district_id')
              ->setParameter('district_id', $districtId);
      }


      if($year){
          $query
              ->andWhere('YEAR(ch.dateOfBirth) = :birth')
              ->setParameter('birth', (int)$year);

      }

      if( array_key_exists('gender', $filters) && !is_null($filters['gender'])){

          $gender = $filters['gender'];

          if(array_key_exists('0', $gender) && !is_null($gender['0']) && $gender['0']){
              $query
                  ->andWhere('ch.gender = :girl ')
                  ->setParameter('girl', Child::GIRL);
          }

          if(array_key_exists('1', $gender) && !is_null($gender['1']) && $gender['1']){
              $query
                  ->andWhere('ch.gender = :boy ')
                  ->setParameter('boy', Child::BOY);
          }
      }

      if( array_key_exists('text', $filters) && !is_null($filters['text'])){

          $inputStrings = trim($filters['text']);
          $inputStrings = preg_split("/[\s]+/", $inputStrings);


          if(array_key_exists(1, $inputStrings)){

              $query
                  ->andWhere('ch.name like :textname AND ch.surname like :textsurname ')
                  ->setParameter('textname', $inputStrings[0] . '%')
                  ->setParameter('textsurname', $inputStrings[1] . '%');

          }
          else{
              $query
                  ->andWhere('ch.name like :textname OR ch.surname like :textname ')
                  ->setParameter('textname', $inputStrings[0] . '%');

          }

      }

      if( array_key_exists('city', $filters) && !is_null($filters['city'])){

          $status = $filters['city'];

          if(array_key_exists(3, $status) && !is_null($status[3]) && $status[3]){

              $query
                  ->andWhere('ch.city = :city ')
                  ->setParameter('city', 3);
          }

          if(array_key_exists(4, $status) && !is_null($status[4]) && $status[4]){
              $query
                  ->andWhere('ch.city = :city ')
                  ->setParameter('city', 4);
          }
      }

      //check order Replace type
      if( array_key_exists('orderReplace', $filters) && !is_null($filters['orderReplace'])  && $filters['orderReplace'] ){
          $query
              ->andWhere('ch.orderReplaceStatus = :orderReplaceStatus ')
              ->setParameter('orderReplaceStatus', true);
      }

      $query
          ->groupBy('ch.id');


      $result = $query->getQuery()->getResult();
      $count = count($result);

      return $count ;

  }

  /**
     * This function is used to get kindergartens register page count
     * @param $kindergarten
     * @param $year
     * @param $districtId
     * @return int|mixed
     */

  public function getOrderReplaceByYearAndKindergarten($kindergarten, $year,$districtId = null){


      $query = $this->getEntityManager()
          ->createQueryBuilder()
          ->select('count(rp)')
          ->from('AppBundle:KindergartenRegisterPage', 'kg')
          ->leftJoin('kg.registerPage', 'rp')
          ->leftJoin('rp.child', 'ch')
          ->leftJoin('kg.kindergarten', 'k')
          ->where('kg.status = :kgStatus')
          ->andWhere('ch.orderReplaceStatus = :orderReplaceStatus')
          ->setParameter('kgStatus', false)
          ->setParameter('orderReplaceStatus', true);

      if($kindergarten){

          $query
              ->andWhere('k.id = :id')
              ->setParameter('id', $kindergarten->getId());
      }
      elseif($districtId != 0 ) {

          $query
              ->andWhere('k.districtId = :district_id')
              ->setParameter('district_id', $districtId);
      }


      if($year){
          $query
              ->andWhere('YEAR(ch.dateOfBirth) = :birth')
              ->setParameter('birth', (int)$year);

      }

      $query
          ->groupBy('ch.id');


      $result = $query->getQuery()->getResult();
      $count = count($result);

      return $count ;

  }
    /**
     * This function is used to get child position in soma kindergarten
     *
     * @param $kindergarten
     * @param $registerPage
     * @return mixed|null
     */
    public function getPositionInKindergarten($kindergarten, $registerPage)
    {
        if(!$kindergarten || !$registerPage){
            return null;
        }

        // create query
        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('krp.position')
            ->from('AppBundle:RegisterPage', 'rp')
            ->leftJoin('rp.kgRegisterPage', 'krp')
            ->leftJoin('krp.kindergarten', 'k')
            ->where('k.id = :kId and rp.id = :rId and krp.position > 0')
            ->orderBy('krp.position', 'ASC')
            ->setParameter('kId', $kindergarten->getId())
            ->setParameter('rId', $registerPage->getId())
        ;

        $returnData = $query->getQuery()->getOneOrNullResult();

        return $returnData['position'];

    }

    /**
     * This function is used to get child position in soma kindergarten
     *
     * @param $child
     * @return mixed|null
     */
    public function getRegisterChildPosition($child)
    {

        // create query
        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.name,c.surname,c.birthCertificate,c.dateOfBirth,krp.position,k.id as kindergarten_id,k.name as kindergarten')
            ->from('AppBundle:RegisterPage', 'rp')
            ->leftJoin('rp.kgRegisterPage', 'krp')
            ->leftJoin('krp.kindergarten', 'k')
            ->leftJoin('rp.child', 'c')
            ->where(' c.id in( :child ) and krp.position > 0')
            ->orderBy('krp.position', 'ASC')
            ->setParameter('child', $child)

        ;

        $returnData = $query->getQuery()->getResult();

        return $returnData;

    }

    /**
     * This function is used to get child position in soma kindergarten white
     *
     * @param $kindergarten
     * @param $year
     * @return int|mixed
     */
    public function getChildInKindergartenGreen($kindergarten, $year)
    {
        //dev master2 
        $nowDate = new \DateTime();
        $YearNow = $nowDate->format('Y');
        if(!$kindergarten || !$year){
            return null;
        }

        // create query
        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('krp.position,c.id as child_id')
            ->from('AppBundle:KindergartenRegisterPage', 'krp')
            ->leftJoin('krp.registerPage', 'rp')
            ->leftJoin('rp.child', 'c')
            ->leftJoin('krp.kindergarten', 'k')
            ->where('k.id = :kId and krp.position > 0 AND krp.year = :year')
//            ->andWhere(' c.city = :city  ')
            ->andWhere(' :YearNow >= YEAR(c.dateOfBirth)+ :intYear')
            ->orderBy('krp.position', 'ASC')
            ->setParameter('kId', $kindergarten->getId())
            ->setParameter('year', $year)
//            ->setParameter('city', 3) // 3 in Yerevan
            ->setParameter('YearNow', $YearNow)
            ->setParameter('intYear', 3)
            ->setMaxResults('1')

        ;
        $returnData = $query->getQuery()->getResult();

        return $returnData;

    }

    /**
     * This function is used to get child position in soma kindergarten blue
     *
     * @param $kindergarten
     * @param $year
     * @return int|mixed
     */
    public function getChildInKindergartenBlue($kindergarten, $year)
    {
        $nowDate = new \DateTime();
        $yearNow = $nowDate->format('Y');
        if(!$kindergarten || !$year){
            return null;
        }

        // create query
        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('krp.position,c.id as child_id')
            ->from('AppBundle:KindergartenRegisterPage', 'krp')
            ->leftJoin('krp.registerPage', 'rp')
            ->leftJoin('rp.child', 'c')
            ->leftJoin('krp.kindergarten', 'k')
            ->where('k.id = :kId and krp.position > 0 AND krp.year = :year')
            ->andWhere(' :yearNow < YEAR(c.dateOfBirth)+ :intYear')
            ->andWhere(' :yearNow >= YEAR(c.dateOfBirth)+ :intYearSmall ')
            ->orderBy('krp.position', 'ASC')
            ->setParameter('kId', $kindergarten->getId())
            ->setParameter('year', $year)
            ->setParameter('yearNow', $yearNow)
            ->setParameter('intYear', 3)
            ->setParameter('intYearSmall', 2)
            ->setMaxResults('1')

        ;
        $returnData = $query->getQuery()->getResult();


        return $returnData;

    }


    public function checkChildRegisterInKindergarten($child,$kindergarten){

        // var_dump(dump($child,$kindergarten));die();
        $query =  $this->getEntityManager()
            ->createQueryBuilder()
            ->Select('rp')
            ->from("AppBundle:RegisterPage","rp")
            ->leftJoin('rp.kgRegisterPage', 'krp')
            ->where("rp.child = :childId")
            ->andwhere("krp.kindergarten = :kidId")
            ->andwhere("krp.position > :position")
            ->setParameter('childId', $child->getId())
            ->setParameter('kidId', $kindergarten)
            ->setParameter('position', 0);

        $child = $query->getQuery()->getResult();
        //var_dump($child);die();
        return $child ? $child : null;
    }

    public function checkChildRegisterInKindergartens($child){

        // var_dump(dump($child,$kindergarten));die();
        $query =  $this->getEntityManager()
            ->createQueryBuilder()
            ->Select('rp')
            ->from("AppBundle:RegisterPage","rp")
            ->leftJoin('rp.kgRegisterPage', 'krp')
            ->where("rp.child = :childId")
            ->andwhere("krp.position > :position")
            ->setParameter('childId', $child->getId())
            ->setParameter('position', 0);

        $child = $query->getQuery()->getResult();
        //var_dump($child);die();
        return $child ? $child : null;
    }



    /**
     * This function is used to get kindergartens register page
     * @param $kindergarten
     * @param $year
     * @return array
     */
    public function getRegisterPagesByYear($kindergarten, $year=null)
    {


        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select( 'krp.position', 'ch.name as name','ch.city','ch.surname as surname','ch.birthCertificate as certificate','ch.dateOfBirth','kt.id as kid_type','ch.orderReplaceStatus')
            ->from('AppBundle:RegisterPage', 'rp')
            ->leftJoin('rp.kgRegisterPage', 'krp')
            ->leftJoin('rp.child', 'ch')
            ->innerJoin('krp.kindergarten', 'k')
            ->leftJoin('k.kindergartenType','kt')
            ->where('krp.status = 0')
            ->andWhere('krp.kindergarten = :id')
            ->setParameter('id', $kindergarten);
            if($year){
                $query
                    ->andWhere('YEAR(ch.dateOfBirth) = :birth')
                    ->setParameter('birth', (int)$year);
            }
        $query
            ->groupBy('ch.id')
            ->groupBy('rp.id')
            ->addOrderBy('krp.position', 'ASC')
            ->addOrderBy('ch.id', 'ASC');
            ;

        return $query->getQuery()->getResult();

    }

    /**
     * This function is used to get kindergartens register pages
     * @return array
     */
    public function getRegisterPagesYears()
    {


        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select( 'krp.year')
            ->from('AppBundle:RegisterPage', 'rp')
            ->leftJoin('rp.kgRegisterPage', 'krp')
            ->where('krp.status = 0')
            ->andWhere('krp.position > 0')
            ->groupBy('krp.year')
            ->orderBy('krp.year', 'ASC')

        ;
        return $query->getQuery()->getResult();

    }

}