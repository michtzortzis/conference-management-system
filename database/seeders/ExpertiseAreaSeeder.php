<?php

namespace Database\Seeders;

use App\Models\ExpertiseArea;
use Illuminate\Database\Seeder;

class ExpertiseAreaSeeder extends Seeder
{
    /**
     * Reference expertise areas, keyed conceptually on name so re-seeding
     * updates the existing rows instead of duplicating reference data.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public static function areas(): array
    {
        return [
            [
                'name' => 'Artificial Intelligence & Machine Learning',
                'description' => 'Techniques and systems that enable computers to learn from data, make predictions, recognise patterns (e.g., neural networks, reinforcement learning), and apply them to domains such as computer vision, natural language processing, autonomous systems.',
            ],
            [
                'name' => 'Data Science & Big Data Analytics',
                'description' => 'Handling, analysing, interpreting and visualising large and complex data sets; extracting insights, building predictive models, data mining, and supporting decision-making in business, science or engineering.',
            ],
            [
                'name' => 'Computer Networks, Communications & Internet of Things (IoT)',
                'description' => 'Design, implementation and management of wired/wireless communication systems, network protocols, sensor networks and IoT architectures, enabling connected devices and distributed systems.',
            ],
            [
                'name' => 'Embedded Systems, Cyber-Physical Systems & Smart Devices',
                'description' => 'Design and integration of computing hardware and software in devices, sensors, actuators, drones, robotics, smart infrastructure, where computing interacts with the physical world.',
            ],
            [
                'name' => 'Cybersecurity, Privacy & Cryptography',
                'description' => 'Protecting systems, networks and data from threats; foundational cryptographic methods; securing communications; ensuring privacy and trustworthiness of computing systems.',
            ],
            [
                'name' => 'Software Engineering, Information Systems & Human-Computer Interaction',
                'description' => 'Methods for designing, developing, testing and maintaining software systems; including usability, user interfaces, software architecture, user experience and interactive systems.',
            ],
            [
                'name' => 'Computer Graphics, Virtual & Augmented Reality',
                'description' => 'Techniques for generating and rendering images/simulations, immersive environments (VR/AR), 3D modelling, image synthesis, and their applications (e.g., simulation, training, entertainment).',
            ],
            [
                'name' => 'Hardware & Computer Systems Architecture',
                'description' => 'Structure and design of computer hardware (processors, memory, storage), system architecture (edge computing, neuromorphic computing, high-performance computing), and hardware–software co-design.',
            ],
            [
                'name' => 'Smart Systems, Industry 4.0 & Digital Twin Technologies',
                'description' => 'Application of computing to industrial, manufacturing and urban-infrastructure settings — e.g., smart factories, smart cities, digital twins, autonomous systems, precision agriculture.',
            ],
            [
                'name' => 'Edge/Cloud Computing & Distributed Systems',
                'description' => 'Architectures for computation that span cloud, fog and edge devices; distributed data processing, scalable systems; virtualization; service-oriented, resilient infrastructures.',
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::areas() as $area) {
            ExpertiseArea::updateOrCreate(
                ['name' => $area['name']],
                ['description' => $area['description']],
            );
        }
    }
}
