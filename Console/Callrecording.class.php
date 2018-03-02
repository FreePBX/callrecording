<?php
// vim: set ai ts=4 sw=4 ft=php:
// Namespace should be FreePBX\Console\Command
namespace FreePBX\Console\Command;

// Symfony stuff all needed add these
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
class Callrecording extends Command {
	protected function configure(){
		$this->setName('recordings')
			->setDescription(_('Call Recordings Commands'))
			->setDefinition(
			array(
				new InputOption('cleanempty', 'c', InputOption::VALUE_NONE, 'Cleanup zero second recordings')
			)
		);
    }
    protected function execute(InputInterface $input, OutputInterface $output){
        $dir = \FreePBX::Config()->get('ASTSPOOLDIR');
        if($input->getOption('cleanempty')){
            $finder = new Finder();
            $finder->in($dir.'/monitor')->size('< 1k');
            foreach ($finder as $file) {
                unlink($file->getRealPath());
            }
            return;
        }
    }
}