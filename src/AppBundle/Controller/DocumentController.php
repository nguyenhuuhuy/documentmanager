<?php

namespace AppBundle\Controller;

use P5\Model\Document;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    /**
     * @Route("/documents/{folder_id}", name="documents", defaults={"folder_id"=null})
     * @Template()
     */
    public function indexAction(Request $request, $folder_id)
    {
        $em = $this->getDoctrine()->getManager();

        $documentRepository = $em->getRepository('P5:Document');
        $folderRepository = $em->getRepository('P5:Folder');

        $currentFolder = null;
        if ($folder_id != null) {
            $currentFolder = $folderRepository->find($folder_id);
            $query = $documentRepository->getMyDocuments($this->getUser(), $currentFolder);
        } else {
            $query = $documentRepository->getMyDocuments($this->getUser());
        }

        $paginator = $this->get('knp_paginator');
        $documents = $paginator->paginate($query, $request->query->getInt('page', 1), $request->query->getInt('size', 10));

        $authors = $documentRepository->getAllAuthors();
        $folders = $documentRepository->getAllFolders();
        $parameters = array(
            'documents' => $documents,
            'authors' => $authors,
            'folders' => $folders,
            'document_types' => $this->getParameter('document_types'),
        );

        if ($currentFolder != null) {
            $parameters['currentFolder'] = $currentFolder;
        }

        return $parameters;
    }

    /**
     * @Route("/shared-documents", name="list_shared_documents")
     * @Template()
     */
    public function listSharedAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $documentRepository = $em->getRepository('P5:Document');
        $authors = $documentRepository->getAllAuthors();
        $folders = $documentRepository->getAllFolders();

        $paginator = $this->get('knp_paginator');
        $documents = $paginator->paginate($this->getUser()->getSharingDocuments(), $request->query->getInt('page', 1), $request->query->getInt('size', 10));

        return array(
            'authors' => $authors,
            'folders' => $folders,
            'documents' => $documents,
        );
    }

    /**
     * @var int
     *
     * @return link
     * @Route("/{id}/sharing", name="document_sharing")
     * @Template()
     */
    public function sharingAction($id, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $callback = $request->get('callbackRoute');
        $userRepository = $em->getRepository('P5:User');
        $documentRepository = $em->getRepository('P5:Document');

        $doc = $documentRepository->find($id);

        $sharingForm = $this->createFormBuilder($doc)
            ->add('sharing_users', 'entity', array(
                'class' => 'P5:User',
                'property' => 'username',
                'multiple' => true,
                'attr' => array(
                    'class' => 'multi-select',
                ),
            ))
            ->setAction($this->generateUrl('document_sharing', array('id' => $id)))
            ->getForm();
        $sharingForm->handleRequest($request);
        if ($sharingForm->isValid()) {
            $data = $request->request->get('form');

            foreach ($data['sharing_users'] as $value) {
                $user = $userRepository->find($value);
                if (!$doc->hasSharingUsers($user)) {
                    $doc->getSharingUsers()->add($user);
                }
            }
            $em->persist($doc);
            $em->flush();

            //push notification
            $messageCenter = $this->get('p5notification.messagecenter');
            $messageCenter->pushMessage($this->getUser(), 'A document was shared to you by '.$this->getUser()->getEmail(), 'document', array('id' => $doc->getId()), $doc->getSharingUsers());

            $this->get('session')->getFlashBag()->add('success', 'Sharing document success!');

            if ($callback) {
                return $this->redirectToRoute($callback, array('id' => $id));
            } else {
                return $this->redirect($this->generateUrl('documents'));
            }
        }

        return array(
            'sharingForm' => $sharingForm->createView(),
            'callbackRoute' => $callback,
        );
    }

    /**
     * @var int
     * @Route("/remove_document/{id}", name="remove_document")
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function removeAction($id, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $documentRepository = $em->getRepository('P5:Document');
        $document = $documentRepository->find($id);
        $em->remove($document);
        $em->flush();
        $this->get('session')->getFlashBag()->add('success', 'The document was removed sucessfully!');

        return $this->redirectToRoute('documents');
    }

    /**
     * @var int
     * @Route("/document/{id}", name="document_details")
     * @Template()
     *
     * @return array
     */
    public function showAction($id, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $documentRepository = $em->getRepository('P5:Document');
        $document = $documentRepository->find($id);
        $folder = $document->getFolder();
        $folderTree = array();
        $level = $folder->getLvl();
        for ($i = 0; $i <= $level; ++$i) {
            $folderTree[$i] = $folder;
            $folder = $folder->getParent();
        }

        return array(
            'document' => $document,
            'user' => $this->get('security.token_storage')->getToken()->getUser(),
            'folderTree' => array_reverse($folderTree),
        );
    }

    /**
     * @var int
     * @Route("/document/edit/{id}", name="edit_document")
     * @Template()
     *
     * @return array
     */
    public function editAction($id, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $documentRepository = $em->getRepository('P5:Document');
        $document = $documentRepository->find($id);

        $form = $this->createFormBuilder($document, array('attr' => array('name' => 'edit_form')))
            ->add('filename', 'text', array('label' => 'Filename'))
            ->add('description', 'textarea', array('label' => 'Description', 'attr' => array('rows' => '12')))
            ->setAction($this->generateUrl('documents'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {
            $em->persist($document);
            $em->flush();
            $this->get('session')->getFlashBag()->add('success', 'The details of the document was updated successfully!');

            return $this->redirectToRoute('document_details', array('id' => $id));
        }

        return array(
            'document' => $document,
            'editForm' => $form->createView(),
        );
    }

    /**
     * @Route("add-document", name="add_document")
     * @Template()
     */
    public function addAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $documentRepository = $em->getRepository('P5:Document');
        $folderRepository = $em->getRepository('P5:Folder');
        $query = $folderRepository->createQueryBuilder('f')
            ->select('f')
            ->where('f.user = :user')
            ->setParameter('user', $this->getUser())
            ->orderBy('f.root, f.lft', 'ASC');
        $folders = $query->getQuery()->getResult();
        $document = new Document();
        $form = $this->createFormBuilder($document, array('attr' => array('name' => 'upload_form')))
            ->add('filename', 'text', array('label' => 'Filename'))
            ->add('type', 'choice', array('choices' => $this->getParameter('document_types'), 'placeholder' => '--Choose a type--'))
            ->add('folder', 'entity', array('choices' => $folders, 'class' => 'P5\Model\Folder', 'property' => 'nameHierarchy', 'placeholder' => '--Choose a folder--'))
            ->setAction($this->generateUrl('add_document'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {
            $document->setUser($this->getUser());
            $document->setFolder($folderRepository->find($document->getFolder()));
            $document->setUploadDate(new \DateTime());
            $document->setLastModified(new \DateTime());
            $document->setDescription('Upload by '.$this->getUser()->getEmail());

            $em->persist($document);
            $em->flush();

            $messageCenter = $this->get('p5notification.messagecenter');
            $messageCenter->pushMessage($this->getUser(), 'A new document was uploaded by '.$this->getUser()->getEmail(), 'document');

            $this->addFlash(
                'success',
                'Your document was uploaded successfully!'
            );

            //return $this->redirect($this->generateUrl('documents'));
            //close iframe
            //return new Response('<script language="JavaScript">parent.$.colorbox.close()</script>');
            return new Response('<script language="JavaScript">parent.location.href="'.$this->generateUrl('documents').'"</script>');
        }

        return array(
            'uploadForm' => $form->createView(),
            'folders' => $folders,
            'document_types' => $this->getParameter('document_types'),
        );
    }
}
