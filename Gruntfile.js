module.exports = function ( grunt ) {
	grunt.initConfig( {
		wp_readme_to_markdown: {
			readme: {
				files: {
					'README.md': 'readme.txt',
				},
			},
		},
	} );

	grunt.loadNpmTasks( 'grunt-wp-readme-to-markdown' );

	grunt.registerTask( 'readme', [ 'wp_readme_to_markdown' ] );
};
